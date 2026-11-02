<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Nova\Versandkosten\Energy\EnergyEntry;
use Nova\Versandkosten\Energy\EnergyState;
use Nova\Versandkosten\Energy\EnergyParseException;
use Nova\Versandkosten\Energy\EnergySurchargeParser;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Energiezuschläge von DHL und DPD abrufen (geplante Aufgabe täglich + Knopf „Jetzt abrufen“).
 *
 * Gespeichert werden die ganzen Tabellen (je Eintrag „gültig ab“ + %), damit beim Monatswechsel sofort
 * der neue Wert gilt, auch wenn der nächste Abruf ausfällt. Bei Fehlern (Seite nicht erreichbar,
 * geändert, Wert außerhalb 0–60 %) bleiben die letzten Werte stehen; es gibt eine Admin-Benachrichtigung
 * und optional eine E-Mail. Je Dienstleister lässt sich in den Einstellungen „manuell“ wählen.
 */
class EnergySurchargeService
{
    public const URL_DHL = 'https://www.dhl.de/energiezuschlag';
    public const URL_DPD = 'https://www.dpd.de/energiezuschlag';
    public const URL_DPD_EFS = 'https://www.dpd.com/de/de/support/versandbestimmungen/emergency-fuel-surcharge/';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly Settings $settings,
        private readonly NotificationService $notifications,
        private readonly AbstractMailService $mailService,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
        private readonly ?ClientInterface $client = null,
    ) {
    }

    /**
     * Abrufen, prüfen, speichern. Liefert den neuen Zustand (inkl. Fehlern je Quelle).
     *
     * @return array<string, mixed>
     */
    public function fetch(Context $context): array
    {
        $state = $this->settings->energyState();
        $state['series'] = \is_array($state['series'] ?? null) ? $state['series'] : [];
        $state['sources'] = \is_array($state['sources'] ?? null) ? $state['sources'] : [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $errors = [];

        $jobs = [
            'dhl' => [self::URL_DHL, EnergySurchargeParser::parseDhl(...)],
            'dpd' => [self::URL_DPD, EnergySurchargeParser::parseDpd(...)],
            'dpd_efs' => [self::URL_DPD_EFS, EnergySurchargeParser::parseDpdEmergency(...)],
        ];
        foreach ($jobs as $series => [$url, $parser]) {
            $source = \is_array($state['sources'][$series] ?? null) ? $state['sources'][$series] : [];
            $source['url'] = $url;
            $source['attemptedAt'] = $now->format(\DATE_ATOM);
            try {
                $entries = $parser($this->download($url));
                self::assertPlausible($entries, $now);
                $state['series'][$series] = array_map(static fn (EnergyEntry $e) => $e->toArray(), $entries);
                $source['fetchedAt'] = $now->format(\DATE_ATOM);
                $source['error'] = null;
            } catch (\Throwable $e) {
                $source['error'] = $e->getMessage();
                $errors[$series] = $e->getMessage();
                $this->logger->warning('Nova Versandkosten: Energiezuschlag nicht abrufbar', ['series' => $series, 'error' => $e->getMessage()]);
            }
            $state['sources'][$series] = $source;
        }

        $this->config->set(Settings::ENERGY_STATE_KEY, $state);
        if ($errors !== []) {
            $this->reportErrors($errors, $context);
        }

        return $this->status($now);
    }

    /**
     * Anzeige im Admin: je Dienstleister gültiger Wert heute, Bestandteile, Quelle, Abrufzeit, Fehler.
     *
     * @return array<string, mixed>
     */
    public function status(?\DateTimeImmutable $date = null): array
    {
        $date ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $state = $this->settings->energyState();
        $sources = \is_array($state['sources'] ?? null) ? $state['sources'] : [];
        $out = [];
        foreach (['dhl', 'dpd', 'dpd_efs'] as $series) {
            $entries = EnergyState::entries($state, $series);
            $current = EnergyEntry::valueAt($entries, $date);
            $out[$series] = [
                'current' => $current?->toArray(),
                'entries' => array_map(static fn (EnergyEntry $e) => $e->toArray(), \array_slice($entries, -6)),
                'source' => $sources[$series] ?? null,
            ];
        }

        return [
            'date' => $date->format('Y-m-d'),
            'series' => $out,
            'total' => [
                'dhl' => EnergyState::valueAt($state, 'dhl', $date),
                'dpd' => EnergyState::valueAt($state, 'dpd', $date, true),
                'dpdWithoutEmergency' => EnergyState::valueAt($state, 'dpd', $date, false),
            ],
            'modes' => [
                'dhl' => $this->settings->string('energyModeDhl', null, 'auto') ?: 'auto',
                'dpd' => $this->settings->string('energyModeDpd', null, 'auto') ?: 'auto',
            ],
            'effective' => $this->settings->energyPercent(null, $date),
        ];
    }

    /**
     * Plausibel: der heute gültige und alle künftigen Werte zwischen 0 und 60 %, mindestens ein Wert gilt
     * heute oder später.
     *
     * @param list<EnergyEntry> $entries
     */
    public static function assertPlausible(array $entries, \DateTimeImmutable $date): void
    {
        $current = EnergyEntry::valueAt($entries, $date);
        if ($current === null) {
            throw new EnergyParseException('Kein Wert für ' . $date->format('m/Y') . ' gefunden');
        }
        foreach ($entries as $entry) {
            if ($entry->validFrom >= $current->validFrom && !EnergyEntry::isPlausible($entry->percent)) {
                throw new EnergyParseException(\sprintf('Unplausibler Wert %.2f %% ab %s (erlaubt 0–60 %%)', $entry->percent, $entry->validFrom));
            }
        }
    }

    private function download(string $url): string
    {
        $client = $this->client ?? new Client();
        $response = $client->request('GET', $url, [
            'timeout' => 20,
            'connect_timeout' => 10,
            'allow_redirects' => ['max' => 5],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; NovaVersandkosten/1.1; +https://github.com/DrdotHouse2106/SW6-Nova-Versandkosten)',
                'Accept' => 'text/html',
                'Accept-Language' => 'de-DE,de;q=0.9',
            ],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new EnergyParseException('HTTP ' . $response->getStatusCode());
        }
        $body = (string) $response->getBody();
        if (\strlen($body) < 500) {
            throw new EnergyParseException('Seite leer');
        }

        return $body;
    }

    /**
     * @param array<string, string> $errors
     */
    private function reportErrors(array $errors, Context $context): void
    {
        $names = ['dhl' => 'DHL', 'dpd' => 'DPD', 'dpd_efs' => 'DPD Emergency Fuel Surcharge'];
        $lines = [];
        foreach ($errors as $series => $message) {
            $lines[] = ($names[$series] ?? $series) . ': ' . $message;
        }
        $text = 'Nova Versandkosten: Energiezuschlag konnte nicht abgerufen werden – der letzte Wert gilt weiter. ' . implode('; ', $lines);

        try {
            $this->notifications->createNotification([
                'id' => Uuid::randomHex(),
                'status' => 'warning',
                'message' => mb_substr($text, 0, 1000),
                'adminOnly' => false,
                'requiredPrivileges' => ['system.system_config'],
            ], $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Admin-Benachrichtigung fehlgeschlagen', ['error' => $e->getMessage()]);
        }

        if (!$this->settings->bool('energyMailOnError', null, false)) {
            return;
        }
        $recipient = $this->settings->string('energyMailRecipient', null) ?: $this->settings->coreEmail(null);
        if ($recipient === '') {
            return;
        }
        $salesChannelId = $this->defaultSalesChannelId();
        if ($salesChannelId === null) {
            return;
        }
        try {
            $this->mailService->send([
                'recipients' => [$recipient => $recipient],
                'salesChannelId' => $salesChannelId,
                'senderName' => 'Nova Versandkosten',
                'subject' => 'Energiezuschlag nicht abrufbar',
                'contentHtml' => nl2br(htmlspecialchars($text . "\n\nBitte unter Bestellungen → Versandkosten → Zuschläge & Energie prüfen.")),
                'contentPlain' => $text . "\n\nBitte unter Bestellungen → Versandkosten → Zuschläge & Energie prüfen.",
            ], $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Energiezuschlag-E-Mail fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    /** E-Mails brauchen einen Verkaufskanal (Absender): erster aktiver Storefront-Kanal. */
    private function defaultSalesChannelId(): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = UNHEX(:storefront) AND active = 1 ORDER BY created_at ASC LIMIT 1',
            ['storefront' => '8a243080f92e4c719546314b577cf82b']
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }
}
