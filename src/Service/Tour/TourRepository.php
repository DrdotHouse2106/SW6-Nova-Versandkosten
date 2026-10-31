<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service\Tour;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\Tour\GeoPoint;
use Nova\Versandkosten\Calculator\Tour\TourDate;
use Nova\Versandkosten\Calculator\Tour\TourPlanner;
use Nova\Versandkosten\Calculator\Tour\TourPriceProfile;
use Nova\Versandkosten\Calculator\Tour\TourPricing;
use Nova\Versandkosten\Calculator\Tour\TourVariant;
use Nova\Versandkosten\Installer\CustomFieldInstaller as CF;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Lesen und Festlegen von Strecken und Tourterminen (DBAL, je Anfrage gemerkt) sowie die Liste
 * „Tour-Lieferungen“ eines Termins.
 */
class TourRepository implements ResetInterface
{
    /** @var list<TourDate>|null */
    private ?array $bookable = null;

    /** @var array<string, array<string, mixed>> Termin-ID → Zeile */
    private array $rows = [];

    /** @var array<string, TourPriceProfile>|null Profil-ID → Preisprofil */
    private ?array $profiles = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->bookable = null;
        $this->rows = [];
        $this->profiles = null;
    }

    public static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
    }

    /**
     * Termine, die noch gebucht werden könnten (offen/festgelegt, Datum ab heute).
     *
     * @return list<TourDate>
     */
    public function bookableDates(): array
    {
        if ($this->bookable !== null) {
            return $this->bookable;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(d.id)) AS id, LOWER(HEX(d.route_id)) AS route_id, LOWER(HEX(d.variant_id)) AS variant_id,
                    LOWER(HEX(d.price_profile_id)) AS price_profile_id, d.name, d.tour_date, d.order_deadline, d.trailer, d.status, d.waypoints, r.name AS route_name
               FROM nova_vk_tour_date d LEFT JOIN nova_vk_tour_route r ON r.id = d.route_id
              WHERE d.status IN (:s) AND d.tour_date >= :today
              ORDER BY d.tour_date',
            ['s' => [TourDate::STATUS_OPEN, TourDate::STATUS_FIXED], 'today' => self::today()->format('Y-m-d')],
            ['s' => ArrayParameterType::STRING]
        );

        return $this->bookable = $this->build($rows);
    }

    /**
     * Ein Termin (beliebiger Status) – null, wenn es ihn nicht gibt.
     */
    public function date(string $id): ?TourDate
    {
        $row = $this->row($id);

        return $row === null ? null : ($this->build([$row])[0] ?? null);
    }

    /**
     * Rohdaten eines Termins inkl. Streckenname.
     *
     * @return array<string, mixed>|null
     */
    public function row(string $id): ?array
    {
        $id = strtolower($id);
        if (!Uuid::isValid($id)) {
            return null;
        }
        if (isset($this->rows[$id])) {
            return $this->rows[$id];
        }
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(d.id)) AS id, LOWER(HEX(d.route_id)) AS route_id, LOWER(HEX(d.variant_id)) AS variant_id,
                    LOWER(HEX(d.price_profile_id)) AS price_profile_id, d.name, d.tour_date, d.order_deadline, d.trailer, d.status, d.waypoints, d.notified_at, r.name AS route_name
               FROM nova_vk_tour_date d LEFT JOIN nova_vk_tour_route r ON r.id = d.route_id
              WHERE d.id = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        return $row === false ? null : $this->rows[$id] = $row;
    }

    /**
     * Name eines Termins für die Administration (Termin-Name, sonst Streckenname).
     *
     * @param array<string, mixed> $row
     */
    public static function label(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));

        return $name !== '' ? $name : trim((string) ($row['route_name'] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<TourDate>
     */
    private function build(array $rows): array
    {
        $routeIds = [];
        foreach ($rows as $row) {
            if (\is_string($row['route_id'] ?? null) && $row['route_id'] !== '') {
                $routeIds[] = $row['route_id'];
            }
        }
        $variants = $this->variantsByRoute(array_values(array_unique($routeIds)));

        $out = [];
        foreach ($rows as $row) {
            $own = TourVariant::pointsFromArray(json_decode((string) ($row['waypoints'] ?? ''), true));
            $list = $own !== []
                ? [new TourVariant(TourVariant::CUSTOM, 'eigene Wegpunkte', $own)]
                : ($variants[(string) ($row['route_id'] ?? '')] ?? []);
            try {
                $date = new \DateTimeImmutable((string) $row['tour_date']);
                $deadline = new \DateTimeImmutable((string) $row['order_deadline']);
            } catch (\Exception) {
                continue;
            }
            $status = \in_array($row['status'], TourDate::STATUSES, true) ? (string) $row['status'] : TourDate::STATUS_CLOSED;
            $fixed = \is_string($row['variant_id'] ?? null) && $row['variant_id'] !== '' ? $row['variant_id'] : null;
            if ($own !== [] && $status === TourDate::STATUS_FIXED) {
                $fixed = TourVariant::CUSTOM;
            }
            $profileId = \is_string($row['price_profile_id'] ?? null) ? $row['price_profile_id'] : '';
            $profile = $profileId !== '' ? ($this->profiles()[$profileId] ?? null) : null;
            $out[] = new TourDate((string) $row['id'], $date, $deadline, (bool) $row['trailer'], $status, $list, $fixed, self::label($row), $profile);
        }

        return $out;
    }

    /**
     * @param list<string> $routeIds
     *
     * @return array<string, list<TourVariant>>
     */
    private function variantsByRoute(array $routeIds): array
    {
        if ($routeIds === []) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(route_id)) AS route_id, name, waypoints
               FROM nova_vk_tour_variant WHERE route_id IN (:ids) ORDER BY position, name',
            ['ids' => Uuid::fromHexToBytesList($routeIds)],
            ['ids' => ArrayParameterType::BINARY]
        );
        $out = [];
        foreach ($rows as $row) {
            $points = TourVariant::pointsFromArray(json_decode((string) ($row['waypoints'] ?? ''), true));
            if ($points !== []) {
                $out[(string) $row['route_id']][] = new TourVariant((string) $row['id'], (string) $row['name'], $points);
            }
        }

        return $out;
    }

    /**
     * Alle Preisprofile (je Anfrage gemerkt), sortiert nach Position und Name.
     *
     * @return array<string, TourPriceProfile>
     */
    public function profiles(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }
        $this->profiles = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, name, small_price, small_max_km, freight_tiers FROM nova_vk_tour_price_profile ORDER BY position, name'
        );
        foreach ($rows as $row) {
            $this->profiles[(string) $row['id']] = new TourPriceProfile(
                (string) $row['id'],
                (string) $row['name'],
                max(0, (int) $row['small_price']),
                max(0.0, (float) $row['small_max_km']),
                TourPriceProfile::tiersFromArray(json_decode((string) ($row['freight_tiers'] ?? ''), true)),
            );
        }

        return $this->profiles;
    }

    /**
     * Größte Reichweite über Standardprofil und alle Preisprofile (Strecken ohne Termin, Abonnenten-Filter).
     */
    public function maxReachKm(TourPricing $pricing, bool $trailer = true): float
    {
        $max = $pricing->maxReachKm($trailer);
        foreach ($this->profiles() as $profile) {
            $max = max($max, $pricing->withProfile($profile)->maxReachKm($trailer));
        }

        return $max;
    }

    public function variantName(?string $variantId): string
    {
        if ($variantId === null || $variantId === TourVariant::CUSTOM) {
            return $variantId === TourVariant::CUSTOM ? 'eigene Wegpunkte' : '';
        }
        if (!Uuid::isValid($variantId)) {
            return '';
        }
        $name = $this->connection->fetchOne('SELECT name FROM nova_vk_tour_variant WHERE id = :id', ['id' => Uuid::fromHexToBytes($variantId)]);

        return \is_string($name) ? $name : '';
    }

    /**
     * Erste Buchung legt die Variante fest: nur, solange der Termin noch offen ist (atomar).
     *
     * @return bool true = festgelegt oder bereits auf genau diese Variante festgelegt
     */
    public function fixVariant(string $dateId, string $variantId): bool
    {
        if (!Uuid::isValid($dateId)) {
            return false;
        }
        $params = ['id' => Uuid::fromHexToBytes($dateId), 'open' => TourDate::STATUS_OPEN, 'fixed' => TourDate::STATUS_FIXED];
        if ($variantId === TourVariant::CUSTOM) {
            $this->connection->executeStatement('UPDATE nova_vk_tour_date SET status = :fixed, updated_at = NOW(3) WHERE id = :id AND status = :open', $params);
            $this->reset();

            return true;
        }
        if (!Uuid::isValid($variantId)) {
            return false;
        }
        $params['variant'] = Uuid::fromHexToBytes($variantId);
        $changed = $this->connection->executeStatement(
            'UPDATE nova_vk_tour_date SET status = :fixed, variant_id = :variant, updated_at = NOW(3) WHERE id = :id AND status = :open',
            $params
        );
        $this->reset();
        if ($changed > 0) {
            return true;
        }
        $current = $this->connection->fetchOne('SELECT LOWER(HEX(variant_id)) FROM nova_vk_tour_date WHERE id = :id', ['id' => $params['id']]);

        return $current === strtolower($variantId);
    }

    /**
     * Bestellungen mit diesem Tourtermin (Liste „Tour-Lieferungen“), sortiert entlang der Strecke.
     * „Außerhalb der Reichweite“ richtet sich nach dem Preisprofil des Termins.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveries(string $dateId, PostcodeDirectory $postcodes, TourPricing $pricing): array
    {
        $date = $this->date($dateId);
        if ($date === null) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(o.id)) AS id, o.order_number, o.order_date_time, o.custom_fields, o.amount_total,
                    os.technical_name AS order_state, ds.technical_name AS delivery_state, ps.technical_name AS payment_state,
                    oc.first_name AS customer_first_name, oc.last_name AS customer_last_name, oc.company AS customer_company,
                    a.first_name, a.last_name, a.company, a.street, a.zipcode, a.city, co.iso AS country
               FROM `order` o
               INNER JOIN state_machine_state os ON os.id = o.state_id
               LEFT JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
               LEFT JOIN order_delivery d ON d.id = o.primary_order_delivery_id AND d.version_id = o.version_id
               LEFT JOIN state_machine_state ds ON ds.id = d.state_id
               LEFT JOIN order_address a ON a.id = d.shipping_order_address_id AND a.version_id = d.shipping_order_address_version_id
               LEFT JOIN country co ON co.id = a.country_id
               LEFT JOIN order_transaction t ON t.id = o.primary_order_transaction_id AND t.version_id = o.version_id
               LEFT JOIN state_machine_state ps ON ps.id = t.state_id
              WHERE o.version_id = :live AND JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, :path)) = :id',
            ['live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'path' => '$.' . CF::ORDER_TOUR_DATE_ID, 'id' => strtolower($dateId)]
        );

        $variants = $date->bookableVariants();
        $datePricing = $date->pricing($pricing);
        $out = [];
        foreach ($rows as $row) {
            $fields = json_decode((string) ($row['custom_fields'] ?? ''), true);
            $fields = \is_array($fields) ? $fields : [];
            $point = $postcodes->point((string) ($row['country'] ?? 'DE'), (string) ($row['zipcode'] ?? ''));
            $nearest = $point === null ? null : TourPlanner::nearestVariant($variants, $point, $pricing);
            $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            $out[] = [
                'orderId' => (string) $row['id'],
                'orderNumber' => (string) $row['order_number'],
                'orderDate' => (string) $row['order_date_time'],
                'customer' => trim(implode(', ', array_filter([(string) ($row['company'] ?? ''), $name]))),
                'street' => (string) ($row['street'] ?? ''),
                'zipcode' => (string) ($row['zipcode'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'tier' => (string) ($fields[CF::ORDER_TOUR_TIER] ?? ''),
                'deviationKm' => isset($fields[CF::ORDER_TOUR_DEVIATION]) ? (float) $fields[CF::ORDER_TOUR_DEVIATION] : null,
                'variantName' => (string) ($fields[CF::ORDER_TOUR_VARIANT_NAME] ?? ''),
                'currentDeviationKm' => $nearest === null ? null : round($nearest[1], 1),
                'alongKm' => $nearest === null ? null : round($nearest[2], 1),
                'outOfRange' => $nearest !== null && $datePricing->priceCents((string) ($fields[CF::ORDER_TOUR_TIER] ?? 'klein'), $nearest[1], $date->trailer) === null,
                'orderState' => (string) $row['order_state'],
                'deliveryState' => (string) ($row['delivery_state'] ?? ''),
                'paymentState' => (string) ($row['payment_state'] ?? ''),
                'amountTotal' => (float) $row['amount_total'],
            ];
        }
        usort($out, static fn (array $a, array $b) => [$a['alongKm'] ?? \PHP_FLOAT_MAX, $a['orderNumber']] <=> [$b['alongKm'] ?? \PHP_FLOAT_MAX, $b['orderNumber']]);

        return $out;
    }

    /**
     * Strecken und buchbare Termine in Reichweite eines Punkts (Hinweis an „Versandkosten auf Anfrage“).
     * Strecken ohne Termin: größte Reichweite aller Preisprofile; Termine: Reichweite ihres Profils.
     *
     * @return list<array{type: string, name: string, variant: string, km: float, date: string|null, trailer: bool}>
     */
    public function nearby(GeoPoint $point, TourPricing $pricing): array
    {
        $out = [];
        $reach = $this->maxReachKm($pricing);
        $routes = $this->connection->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, name FROM nova_vk_tour_route WHERE active = 1');
        $variants = $this->variantsByRoute(array_map(static fn (array $r) => (string) $r['id'], $routes));
        foreach ($routes as $route) {
            $nearest = TourPlanner::nearestVariant($variants[(string) $route['id']] ?? [], $point, $pricing);
            if ($nearest !== null && $nearest[1] <= $reach) {
                $out[] = ['type' => 'route', 'name' => (string) $route['name'], 'variant' => $nearest[0]->name, 'km' => round($nearest[1], 1), 'date' => null, 'trailer' => false];
            }
        }
        foreach ($this->bookableDates() as $date) {
            $km = TourPlanner::inCorridor($date, $point, $pricing);
            if ($km !== null) {
                $nearest = TourPlanner::nearestVariant($date->bookableVariants(), $point, $pricing);
                $out[] = ['type' => 'date', 'name' => $date->name, 'variant' => $nearest === null ? '' : $nearest[0]->name, 'km' => round($km, 1), 'date' => $date->date->format('Y-m-d'), 'trailer' => $date->trailer];
            }
        }
        usort($out, static fn (array $a, array $b) => $a['km'] <=> $b['km']);

        return $out;
    }
}
