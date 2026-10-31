<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service\Tour;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\Tour\GeoPoint;
use Nova\Versandkosten\Calculator\Tour\TourDate;
use Nova\Versandkosten\Calculator\Tour\TourPlanner;
use Nova\Versandkosten\Calculator\Tour\TourText;
use Nova\Versandkosten\Calculator\Tour\TourToken;
use Nova\Versandkosten\Calculator\Tour\TourVariant;
use Nova\Versandkosten\Installer\MailTemplateInstaller;
use Nova\Versandkosten\Service\RequestOrderService;
use Nova\Versandkosten\Service\Settings;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Tour-Benachrichtigung („Benachrichtigen, wenn eine Tour in die Nähe fährt“).
 *
 * - Gäste/Besucher: Formular (E-Mail + PLZ + Einwilligung) → Double-Opt-in-Mail, erst nach Bestätigung aktiv.
 * - Kundenkonto: Schalter je Lieferadresse (Kunde ist angemeldet, die Adresse gehört ihm) → sofort aktiv;
 *   gesendet wird an die aktuelle E-Mail des Kundenkontos.
 * - Abmelden über den Link in jeder Mail (HMAC-Signatur), „weiter benachrichtigen“ verlängert die Frist.
 * - Versand nur auf Knopfdruck in der Administration, einmal je Termin und Abonnent (Protokolltabelle).
 * - Aufräumen täglich: unbestätigte Anmeldungen nach 7 Tagen, aktive nach X Monaten ohne Aktivität.
 */
class TourNotificationService
{
    public const PATH_CONFIRM = '/nova-versandkosten/tour-benachrichtigung/bestaetigen/';
    public const PATH_UNSUBSCRIBE = '/nova-versandkosten/tour-benachrichtigung/abmelden/';
    public const PATH_RENEW = '/nova-versandkosten/tour-benachrichtigung/behalten/';

    public const SOURCE_FORM = 'form';
    public const SOURCE_ACCOUNT = 'account';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';

    public function __construct(
        private readonly Connection $connection,
        private readonly AbstractMailService $mailService,
        private readonly PostcodeDirectory $postcodes,
        private readonly TourRepository $tours,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly string $secret,
    ) {
    }

    public function enabled(?string $salesChannelId): bool
    {
        return $this->settings->bool('tourNotifyEnabled', $salesChannelId, false);
    }

    /**
     * Anmeldung über das Formular. Antwort für den Besucher ist immer gleich („Bitte bestätigen …“), damit
     * nicht erkennbar ist, ob eine Adresse schon angemeldet ist. Fehlercodes nur für Eingabefehler.
     *
     * @return 'ok'|'email'|'postcode'
     */
    public function subscribe(string $email, string $postcode, string $salesChannelId, string $languageId): string
    {
        $email = mb_strtolower(trim($email));
        $postcode = PostcodeDirectory::normalize($postcode);
        if (!TourToken::validEmail($email)) {
            return 'email';
        }
        $country = $this->settings->tourPricing($salesChannelId)->countries[0] ?? 'DE';
        $hit = preg_match('/^\d{4,5}$/', $postcode) === 1 ? $this->postcodes->lookup($country, $postcode) : null;
        if ($hit === null) {
            return 'postcode';
        }

        $existing = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, status FROM nova_vk_tour_subscriber
              WHERE email = :e AND postcode = :p AND customer_address_id IS NULL LIMIT 1',
            ['e' => $email, 'p' => $postcode]
        );
        if ($existing !== false && $existing['status'] === self::STATUS_ACTIVE) {
            // Schon aktiv: nichts verraten, Frist verlängern
            $this->touch((string) $existing['id']);

            return 'ok';
        }

        $token = TourToken::random();
        $now = self::now();
        if ($existing !== false) {
            $this->connection->executeStatement(
                'UPDATE nova_vk_tour_subscriber SET doi_token_hash = :h, created_at = :now, updated_at = :now, language_id = :lang, sales_channel_id = :sc WHERE id = :id',
                ['h' => TourToken::hash($token), 'now' => $now, 'lang' => Uuid::fromHexToBytes($languageId), 'sc' => Uuid::fromHexToBytes($salesChannelId), 'id' => Uuid::fromHexToBytes((string) $existing['id'])]
            );
        } else {
            $this->connection->insert('nova_vk_tour_subscriber', [
                'id' => Uuid::randomBytes(),
                'email' => $email,
                'postcode' => $postcode,
                'country' => $country,
                'place' => $hit['place'],
                'latitude' => $hit['lat'],
                'longitude' => $hit['lon'],
                'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
                'language_id' => Uuid::fromHexToBytes($languageId),
                'source' => self::SOURCE_FORM,
                'status' => self::STATUS_PENDING,
                'doi_token_hash' => TourToken::hash($token),
                'created_at' => $now,
            ]);
        }

        $this->sendTemplate(MailTemplateInstaller::TYPE_TOUR_DOI, $email, $salesChannelId, $languageId, [
            'confirmUrl' => $this->url($salesChannelId, $languageId, self::PATH_CONFIRM . $token),
            'postcode' => $postcode,
            'days' => TourToken::DOI_DAYS,
        ]);

        return 'ok';
    }

    /**
     * Double-Opt-in bestätigen. true = jetzt (oder schon) aktiv.
     */
    public function confirm(string $token): bool
    {
        if (!TourToken::isWellFormed($token)) {
            return false;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, created_at FROM nova_vk_tour_subscriber WHERE doi_token_hash = :h AND status = :s LIMIT 1',
            ['h' => TourToken::hash($token), 's' => self::STATUS_PENDING]
        );
        if ($row === false) {
            return false;
        }
        if (TourToken::doiExpired(new \DateTimeImmutable((string) $row['created_at']), new \DateTimeImmutable())) {
            $this->connection->delete('nova_vk_tour_subscriber', ['id' => $row['id']]);

            return false;
        }
        $now = self::now();
        $this->connection->executeStatement(
            'UPDATE nova_vk_tour_subscriber SET status = :s, doi_token_hash = NULL, confirmed_at = :now, last_activity_at = :now, updated_at = :now WHERE id = :id',
            ['s' => self::STATUS_ACTIVE, 'now' => $now, 'id' => $row['id']]
        );

        return true;
    }

    /**
     * Abmelden über den Link aus einer Mail (löscht den Eintrag). Ungültige Links: false.
     */
    public function unsubscribe(string $id, string $signature): bool
    {
        if (!Uuid::isValid($id) || !TourToken::verify($id, TourToken::ACTION_UNSUBSCRIBE, $signature, $this->secret)) {
            return false;
        }
        $this->connection->delete('nova_vk_tour_subscriber', ['id' => Uuid::fromHexToBytes($id)]);

        return true;
    }

    /**
     * „Weiter benachrichtigen“: Aktivität setzen (verlängert die Löschfrist).
     */
    public function renew(string $id, string $signature): bool
    {
        if (!Uuid::isValid($id) || !TourToken::verify($id, TourToken::ACTION_RENEW, $signature, $this->secret)) {
            return false;
        }

        return $this->touch($id) > 0;
    }

    private function touch(string $id): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE nova_vk_tour_subscriber SET last_activity_at = :now, updated_at = :now WHERE id = :id AND status = :s',
            ['now' => self::now(), 'id' => Uuid::fromHexToBytes($id), 's' => self::STATUS_ACTIVE]
        );
    }

    /**
     * Lieferadressen des Kunden mit Schalterstellung (Kundenkonto-Seite / Store-API).
     *
     * @return list<array{addressId: string, label: string, postcode: string, country: string, possible: bool, subscribed: bool}>
     */
    public function accountAddresses(string $customerId, ?string $salesChannelId): array
    {
        $countries = $this->settings->tourPricing($salesChannelId)->countries;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(a.id)) AS id, a.company, a.first_name, a.last_name, a.street, a.zipcode, a.city, co.iso,
                    (s.id IS NOT NULL) AS subscribed
               FROM customer_address a
               LEFT JOIN country co ON co.id = a.country_id
               LEFT JOIN nova_vk_tour_subscriber s ON s.customer_address_id = a.id
              WHERE a.customer_id = :c ORDER BY a.created_at',
            ['c' => Uuid::fromHexToBytes($customerId)]
        );
        $out = [];
        foreach ($rows as $row) {
            $iso = strtoupper((string) ($row['iso'] ?? ''));
            $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            $out[] = [
                'addressId' => (string) $row['id'],
                'label' => implode(', ', array_filter([(string) ($row['company'] ?? ''), $name, (string) ($row['street'] ?? ''), trim($row['zipcode'] . ' ' . $row['city'])])),
                'postcode' => (string) ($row['zipcode'] ?? ''),
                'country' => $iso,
                'possible' => \in_array($iso, $countries, true) && $this->postcodes->lookup($iso, (string) ($row['zipcode'] ?? '')) !== null,
                'subscribed' => (bool) $row['subscribed'],
            ];
        }

        return $out;
    }

    /**
     * Schalter je Lieferadresse. Nur Adressen des angemeldeten Kunden.
     */
    public function setAccountSubscription(string $customerId, string $addressId, bool $on, string $salesChannelId, string $languageId): bool
    {
        if (!Uuid::isValid($addressId)) {
            return false;
        }
        $address = $this->connection->fetchAssociative(
            'SELECT a.zipcode, a.city, co.iso, c.email FROM customer_address a
              INNER JOIN customer c ON c.id = a.customer_id
              LEFT JOIN country co ON co.id = a.country_id
              WHERE a.id = :a AND a.customer_id = :c',
            ['a' => Uuid::fromHexToBytes($addressId), 'c' => Uuid::fromHexToBytes($customerId)]
        );
        if ($address === false) {
            return false;
        }
        if (!$on) {
            $this->connection->executeStatement('DELETE FROM nova_vk_tour_subscriber WHERE customer_address_id = :a', ['a' => Uuid::fromHexToBytes($addressId)]);

            return true;
        }
        $iso = strtoupper((string) ($address['iso'] ?? ''));
        $hit = \in_array($iso, $this->settings->tourPricing($salesChannelId)->countries, true) ? $this->postcodes->lookup($iso, (string) $address['zipcode']) : null;
        if ($hit === null) {
            return false;
        }
        $now = self::now();
        $this->connection->executeStatement(
            'INSERT INTO nova_vk_tour_subscriber (id, email, postcode, country, place, latitude, longitude, customer_id, customer_address_id,
                    sales_channel_id, language_id, source, status, confirmed_at, last_activity_at, created_at)
             VALUES (:id, :email, :postcode, :country, :place, :lat, :lon, :customer, :address, :sc, :lang, :source, :status, :now, :now, :now)
             ON DUPLICATE KEY UPDATE email = VALUES(email), postcode = VALUES(postcode), country = VALUES(country), place = VALUES(place),
                    latitude = VALUES(latitude), longitude = VALUES(longitude), status = VALUES(status), last_activity_at = VALUES(last_activity_at), updated_at = VALUES(created_at)',
            [
                'id' => Uuid::randomBytes(),
                'email' => mb_strtolower((string) $address['email']),
                'postcode' => PostcodeDirectory::normalize((string) $address['zipcode']),
                'country' => $iso,
                'place' => (string) ($address['city'] ?? ''),
                'lat' => $hit['lat'],
                'lon' => $hit['lon'],
                'customer' => Uuid::fromHexToBytes($customerId),
                'address' => Uuid::fromHexToBytes($addressId),
                'sc' => Uuid::fromHexToBytes($salesChannelId),
                'lang' => Uuid::fromHexToBytes($languageId),
                'source' => self::SOURCE_ACCOUNT,
                'status' => self::STATUS_ACTIVE,
                'now' => $now,
            ]
        );

        return true;
    }

    /**
     * Aktive Abonnenten (für die Administration), optional nur im Umkreis einer Strecke.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $routeId = null, ?float $radiusKm = null, string $search = '', bool $includePending = true): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(s.id)) AS id, COALESCE(c.email, s.email) AS email, s.postcode, s.country, s.place, s.latitude, s.longitude,
                    s.source, s.status, s.created_at, s.confirmed_at, s.last_activity_at, LOWER(HEX(s.customer_id)) AS customer_id,
                    c.customer_number, c.first_name, c.last_name,
                    (SELECT COUNT(*) FROM nova_vk_tour_notification n WHERE n.subscriber_id = s.id) AS notifications
               FROM nova_vk_tour_subscriber s
               LEFT JOIN customer c ON c.id = s.customer_id
              WHERE (:pending = 1 OR s.status = :active)
                AND (:q = \'\' OR s.email LIKE :like OR c.email LIKE :like OR s.postcode LIKE :like OR s.place LIKE :like)
              ORDER BY s.created_at DESC',
            ['pending' => $includePending ? 1 : 0, 'active' => self::STATUS_ACTIVE, 'q' => trim($search), 'like' => '%' . addcslashes(trim($search), '%_\\') . '%']
        );

        $variants = null;
        if ($routeId !== null && Uuid::isValid($routeId)) {
            $variants = $this->routeVariants($routeId);
        }
        $pricing = $this->settings->tourPricing(null);
        $radius = $radiusKm ?? $this->tours->maxReachKm($pricing);

        $out = [];
        foreach ($rows as $row) {
            $km = null;
            if ($variants !== null) {
                $point = self::point($row);
                $nearest = $point === null ? null : TourPlanner::nearestVariant($variants, $point, $pricing);
                if ($nearest === null || $nearest[1] > $radius) {
                    continue;
                }
                $km = round($nearest[1], 1);
            }
            $row['km'] = $km;
            $row['name'] = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            $row['type'] = $row['customer_id'] !== null ? 'customer' : 'guest';
            unset($row['latitude'], $row['longitude'], $row['first_name'], $row['last_name']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<TourVariant>
     */
    private function routeVariants(string $routeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, name, waypoints FROM nova_vk_tour_variant WHERE route_id = :r ORDER BY position',
            ['r' => Uuid::fromHexToBytes($routeId)]
        );
        $out = [];
        foreach ($rows as $row) {
            $points = TourVariant::pointsFromArray(json_decode((string) ($row['waypoints'] ?? ''), true));
            if ($points !== []) {
                $out[] = new TourVariant((string) $row['id'], (string) $row['name'], $points);
            }
        }

        return $out;
    }

    /**
     * Interessenten im Korridor eines Termins (offener Termin: alle Varianten).
     *
     * @return array{total: int, notified: int, open: int, subscribers: list<array{id: string, email: string, postcode: string, km: float, notified: bool}>}
     */
    public function corridor(string $dateId): array
    {
        $date = $this->tours->date($dateId);
        $result = ['total' => 0, 'notified' => 0, 'open' => 0, 'subscribers' => []];
        if ($date === null) {
            return $result;
        }
        $pricing = $this->settings->tourPricing(null);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(s.id)) AS id, COALESCE(c.email, s.email) AS email, s.postcode, s.latitude, s.longitude,
                    (n.subscriber_id IS NOT NULL) AS notified
               FROM nova_vk_tour_subscriber s
               LEFT JOIN customer c ON c.id = s.customer_id
               LEFT JOIN nova_vk_tour_notification n ON n.subscriber_id = s.id AND n.tour_date_id = :d
              WHERE s.status = :active',
            ['d' => Uuid::fromHexToBytes($dateId), 'active' => self::STATUS_ACTIVE]
        );
        foreach ($rows as $row) {
            $point = self::point($row);
            $km = $point === null ? null : TourPlanner::inCorridor($date, $point, $pricing);
            if ($km === null) {
                continue;
            }
            $notified = (bool) $row['notified'];
            ++$result['total'];
            $notified ? ++$result['notified'] : ++$result['open'];
            $result['subscribers'][] = ['id' => (string) $row['id'], 'email' => (string) $row['email'], 'postcode' => (string) $row['postcode'], 'km' => round($km, 1), 'notified' => $notified];
        }
        usort($result['subscribers'], static fn (array $a, array $b) => $a['km'] <=> $b['km']);

        return $result;
    }

    /**
     * Benachrichtigung zu einem Termin senden – an alle Interessenten im Korridor, die zu diesem Termin
     * noch keine Mail bekommen haben.
     *
     * @return array{sent: int, failed: int, skipped: int, error: string|null}
     */
    public function send(string $dateId): array
    {
        $row = $this->tours->row($dateId);
        $date = $this->tours->date($dateId);
        if ($row === null || $date === null) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'Termin nicht gefunden.'];
        }
        if ($date->status === TourDate::STATUS_CLOSED || $date->isPastDeadline(TourRepository::today())) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'Der Termin ist geschlossen oder der Bestellschluss ist vorbei.'];
        }

        $corridor = $this->corridor($dateId);
        $sent = 0;
        $failed = 0;
        foreach ($corridor['subscribers'] as $subscriber) {
            if ($subscriber['notified']) {
                continue;
            }
            $meta = $this->connection->fetchAssociative(
                'SELECT LOWER(HEX(sales_channel_id)) AS sc, LOWER(HEX(language_id)) AS lang FROM nova_vk_tour_subscriber WHERE id = :id',
                ['id' => Uuid::fromHexToBytes($subscriber['id'])]
            );
            $salesChannelId = \is_array($meta) && \is_string($meta['sc']) ? $meta['sc'] : $this->defaultSalesChannelId();
            $languageId = \is_array($meta) && \is_string($meta['lang']) ? $meta['lang'] : Defaults::LANGUAGE_SYSTEM;
            if ($salesChannelId === null) {
                ++$failed;
                continue;
            }
            $german = $this->isGerman($languageId);
            $ok = $this->sendTemplate(MailTemplateInstaller::TYPE_TOUR_NOTIFY, $subscriber['email'], $salesChannelId, $languageId, [
                'date' => $german ? TourText::longDate($date->date) : TourText::date($date->date, 'en'),
                'dateShort' => TourText::date($date->date, $german ? 'de' : 'en'),
                'area' => ($german ? 'PLZ-Gebiet ' : 'postcode area ') . TourText::area($subscriber['postcode']),
                'deadline' => $date->deadline->format($german ? 'd.m.Y' : 'd/m/Y'),
                'trailer' => $date->trailer,
                'shopUrl' => $this->url($salesChannelId, $languageId, '/'),
                'renewUrl' => $this->url($salesChannelId, $languageId, self::PATH_RENEW . $subscriber['id'] . '/' . TourToken::sign($subscriber['id'], TourToken::ACTION_RENEW, $this->secret)),
                'unsubscribeUrl' => $this->url($salesChannelId, $languageId, self::PATH_UNSUBSCRIBE . $subscriber['id'] . '/' . TourToken::sign($subscriber['id'], TourToken::ACTION_UNSUBSCRIBE, $this->secret)),
            ]);
            if (!$ok) {
                ++$failed;
                continue;
            }
            $this->connection->executeStatement(
                'INSERT IGNORE INTO nova_vk_tour_notification (tour_date_id, subscriber_id, sent_at) VALUES (:d, :s, :now)',
                ['d' => Uuid::fromHexToBytes($dateId), 's' => Uuid::fromHexToBytes($subscriber['id']), 'now' => self::now()]
            );
            ++$sent;
        }
        if ($sent > 0) {
            $this->connection->executeStatement('UPDATE nova_vk_tour_date SET notified_at = :now WHERE id = :id', ['now' => self::now(), 'id' => Uuid::fromHexToBytes($dateId)]);
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $corridor['notified'], 'error' => null];
    }

    /**
     * Tägliches Aufräumen: unbestätigte nach 7 Tagen, aktive nach X Monaten ohne Aktivität (0 = nie).
     *
     * @return array{pending: int, inactive: int}
     */
    public function cleanup(): array
    {
        $now = new \DateTimeImmutable();
        $pending = (int) $this->connection->executeStatement(
            'DELETE FROM nova_vk_tour_subscriber WHERE status <> :active AND created_at < :limit',
            ['active' => self::STATUS_ACTIVE, 'limit' => $now->modify('-' . TourToken::DOI_DAYS . ' days')->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
        );
        $months = $this->settings->int('tourNotifyRetentionMonths', null, TourToken::RETENTION_MONTHS);
        $inactive = 0;
        if ($months > 0) {
            $inactive = (int) $this->connection->executeStatement(
                'DELETE FROM nova_vk_tour_subscriber WHERE status = :active AND COALESCE(last_activity_at, confirmed_at, created_at) < :limit',
                ['active' => self::STATUS_ACTIVE, 'limit' => $now->modify('-' . $months . ' months')->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
            );
        }

        return ['pending' => $pending, 'inactive' => $inactive];
    }

    /**
     * CSV (Semikolon, UTF-8 mit BOM für Excel).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Art', 'Kundennummer', 'Name', 'E-Mail', 'PLZ', 'Ort', 'Land', 'Quelle', 'Status', 'Seit', 'Bestätigt', 'Letzte Aktivität', 'Benachrichtigungen', 'Abstand km'], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['type'] === 'customer' ? 'Kunde' : 'Gast',
                (string) ($row['customer_number'] ?? ''),
                (string) ($row['name'] ?? ''),
                self::csvSafe((string) $row['email']),
                (string) $row['postcode'],
                self::csvSafe((string) ($row['place'] ?? '')),
                (string) $row['country'],
                (string) $row['source'],
                (string) $row['status'],
                (string) $row['created_at'],
                (string) ($row['confirmed_at'] ?? ''),
                (string) ($row['last_activity_at'] ?? ''),
                (string) $row['notifications'],
                $row['km'] === null ? '' : number_format((float) $row['km'], 1, ',', ''),
            ], ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Formeln in Excel verhindern (Werte, die mit = + - @ beginnen) */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && \in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function point(array $row): ?GeoPoint
    {
        return is_numeric($row['latitude'] ?? null) && is_numeric($row['longitude'] ?? null)
            ? GeoPoint::fromArray(['lat' => $row['latitude'], 'lon' => $row['longitude']])
            : null;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function sendTemplate(string $type, string $email, string $salesChannelId, string $languageId, array $vars): bool
    {
        $template = $this->template($type, $languageId);
        if ($template === null) {
            $this->logger->warning('Nova Versandkosten: E-Mail-Vorlage fehlt', ['type' => $type]);

            return false;
        }
        $context = new Context(new SystemSource(), [], Defaults::CURRENCY, array_values(array_unique([$languageId, Defaults::LANGUAGE_SYSTEM])));
        try {
            $this->mailService->send([
                'recipients' => [$email => $email],
                'salesChannelId' => $salesChannelId,
                'senderName' => $template['sender_name'],
                'subject' => $template['subject'],
                'contentHtml' => $template['content_html'],
                'contentPlain' => $template['content_plain'],
                'templateId' => $template['id'],
            ], $context, ['novaTour' => $vars]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Tour-Mail fehlgeschlagen', ['type' => $type, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{id: string, sender_name: string, subject: string, content_html: string, content_plain: string}|null
     */
    private function template(string $type, string $languageId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(mt.id)) AS id, tr.sender_name, tr.subject, tr.content_html, tr.content_plain
               FROM mail_template mt
               INNER JOIN mail_template_type ty ON ty.id = mt.mail_template_type_id
               INNER JOIN mail_template_translation tr ON tr.mail_template_id = mt.id
              WHERE ty.technical_name = :type AND tr.subject IS NOT NULL AND tr.content_html IS NOT NULL
              ORDER BY mt.system_default DESC, (tr.language_id = :lang) DESC, (tr.language_id = :system) DESC
              LIMIT 1',
            ['type' => $type, 'lang' => Uuid::fromHexToBytes($languageId), 'system' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
        );
        if ($row === false) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'sender_name' => (string) ($row['sender_name'] ?? ''),
            'subject' => (string) $row['subject'],
            'content_html' => (string) $row['content_html'],
            'content_plain' => (string) ($row['content_plain'] ?? strip_tags((string) $row['content_html'])),
        ];
    }

    private function url(string $salesChannelId, string $languageId, string $path): string
    {
        $url = $this->connection->fetchOne(
            'SELECT url FROM sales_channel_domain WHERE sales_channel_id = :sc
              ORDER BY (language_id = :lang) DESC, (url LIKE \'https%\') DESC, created_at ASC LIMIT 1',
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'lang' => Uuid::fromHexToBytes($languageId)]
        );
        $base = \is_string($url) ? rtrim($url, '/') : rtrim(RequestOrderService::appUrl(), '/');

        return $base . $path;
    }

    private function isGerman(string $languageId): bool
    {
        $code = $this->connection->fetchOne(
            'SELECT locale.code FROM language INNER JOIN locale ON locale.id = language.locale_id WHERE language.id = :id',
            ['id' => Uuid::fromHexToBytes($languageId)]
        );

        return !\is_string($code) || str_starts_with($code, 'de');
    }

    private function defaultSalesChannelId(): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = :type AND active = 1 ORDER BY created_at LIMIT 1',
            ['type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
        );

        return \is_string($id) ? $id : null;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
