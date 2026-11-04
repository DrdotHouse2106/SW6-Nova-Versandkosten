<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Nova\Versandkosten\Calculator\Tariff\TariffException;
use Nova\Versandkosten\Calculator\Tariff\TariffSet;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Speichert die importierte Tarifdatei global in der Datenbank (system_config, nicht im Dateisystem
 * des Plugins). Die Datei wird beim Import geprüft und in der geprüften JSON-Fassung abgelegt.
 *
 * Ist die gespeicherte Datei ungültig (z. B. nach einem Update mit strengeren Regeln), sperrt die Kasse
 * alle Paket-Versandarten des Plugins (fail closed) – dazu gibt es höchstens alle 6 Stunden eine
 * Benachrichtigung in der Administration.
 */
class TariffStore implements ResetInterface
{
    public const KEY_DATA = 'NovaVersandkosten.tariffs.data';
    public const KEY_META = 'NovaVersandkosten.tariffs.meta';

    private ?TariffSet $loaded = null;

    private bool $tried = false;

    private const NOTIFY_CACHE_KEY = 'nova_versandkosten_tariffs_invalid_notified';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ?NotificationService $notifications = null,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    public function reset(): void
    {
        $this->loaded = null;
        $this->tried = false;
    }

    public function get(): ?TariffSet
    {
        if ($this->tried) {
            return $this->loaded;
        }
        $this->tried = true;

        $data = $this->config->get(self::KEY_DATA);
        if (!\is_array($data) || $data === []) {
            return null;
        }

        try {
            return $this->loaded = TariffSet::fromArray($data);
        } catch (TariffException $e) {
            $this->logger->error('Nova Versandkosten: gespeicherte Tarifdatei ungültig', ['errors' => $e->errors]);
            $this->notifyInvalid();

            return null;
        }
    }

    public function save(TariffSet $set, string $fileName): void
    {
        $this->config->set(self::KEY_DATA, $set->data);
        $this->config->set(self::KEY_META, [
            'name' => $set->name,
            'fileName' => $fileName,
            'importedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'products' => \count($set->products),
            'options' => $set->options(),
        ]);
        $this->reset();
    }

    private function notifyInvalid(): void
    {
        if ($this->notifications === null || $this->cache === null) {
            return;
        }
        try {
            $item = $this->cache->getItem(self::NOTIFY_CACHE_KEY);
            if ($item->isHit()) {
                return;
            }
            $item->set(true);
            $item->expiresAfter(6 * 3600);
            $this->cache->save($item);

            $this->notifications->createNotification([
                'id' => Uuid::randomHex(),
                'status' => 'error',
                'message' => 'Nova Versandkosten: Die gespeicherte Tarifdatei ist ungültig. Die Paket-Versandarten des Plugins sind in der Kasse gesperrt, bis unter Bestellungen → Versandkosten → Tarife eine gültige Datei importiert ist.',
                'adminOnly' => false,
                'requiredPrivileges' => ['system.system_config'],
            ], Context::createDefaultContext());
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Admin-Benachrichtigung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed>|null */
    public function meta(): ?array
    {
        $meta = $this->config->get(self::KEY_META);

        return \is_array($meta) ? $meta : null;
    }
}
