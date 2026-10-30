<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\Beipack;
use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Installer\CustomFieldInstaller as CF;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * „Zur offenen Bestellung beipacken“: Bestellungen finden, prüfen und den Differenzpreis berechnen.
 *
 * Die gewählte Bestellung hängt als Erweiterung SELECTION am Warenkorb (wird mit ihm gespeichert):
 * - via „customer“: angemeldeter Kunde hat sie aus seinen Bestellungen gewählt (Besitz wird bei jeder
 *   Berechnung erneut geprüft),
 * - via „code“: über den Link mit Shopwares geheimem Bestell-Code (deepLinkCode) oder über Bestellnummer
 *   + E-Mail + PLZ bestätigt.
 * Status (offen, nicht versendet, nicht storniert, Alter, Land) wird bei JEDER Berechnung frisch aus der
 * Datenbank geprüft – auch beim Abschluss der Bestellung.
 */
class BeipackService implements ResetInterface
{
    public const SELECTION = 'novaVersandkostenBeipackWahl';

    /** @var array<string, array<string, mixed>|null> */
    private array $memo = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly Settings $settings,
        private readonly QuoteService $quotes,
        private readonly ProductClassifier $classifier,
    ) {
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * @return array{orderId: string, via: string}|null
     */
    public function selection(Cart $cart): ?array
    {
        $ext = $cart->getExtension(self::SELECTION);
        if (!$ext instanceof ArrayStruct) {
            return null;
        }
        $orderId = $ext->get('orderId');
        $via = $ext->get('via');
        if (!\is_string($orderId) || !Uuid::isValid($orderId) || !\is_string($via)) {
            return null;
        }

        return ['orderId' => strtolower($orderId), 'via' => $via];
    }

    public function select(Cart $cart, ?string $orderId, string $via): void
    {
        if ($orderId === null) {
            $cart->removeExtension(self::SELECTION);

            return;
        }
        $cart->addExtension(self::SELECTION, new ArrayStruct(['orderId' => strtolower($orderId), 'via' => $via]));
    }

    /**
     * ID der Versandart „beipacken“, wenn sie aktiv und dem Verkaufskanal zugeordnet ist.
     */
    public function methodId(string $salesChannelId): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(sm.id)) FROM shipping_method sm
              INNER JOIN sales_channel_shipping_method scsm ON scsm.shipping_method_id = sm.id
              WHERE sm.technical_name = :name AND sm.active = 1 AND scsm.sales_channel_id = :sc LIMIT 1',
            ['name' => MethodMap::technicalName(OptionResult::BEIPACK), 'sc' => Uuid::fromHexToBytes($salesChannelId)]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Kurzbeschreibung für die Auswahl (nur eigene bzw. bestätigte Bestellungen!).
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: string, orderNumber: string, date: string, address: string}
     */
    public static function summary(array $row): array
    {
        $date = $row['orderDate'] instanceof \DateTimeImmutable ? $row['orderDate']->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y') : '';
        $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
        $place = trim(((string) ($row['zipcode'] ?? '')) . ' ' . ((string) ($row['city'] ?? '')));

        return [
            'id' => (string) $row['id'],
            'orderNumber' => (string) $row['order_number'],
            'date' => $date,
            'address' => implode(', ', array_filter([(string) ($row['company'] ?? ''), $name, (string) ($row['street'] ?? ''), $place])),
        ];
    }

    public function maxAgeDays(?string $salesChannelId): int
    {
        return max(0, $this->settings->int('beipackMaxAgeDays', $salesChannelId, Beipack::DEFAULT_MAX_AGE_DAYS));
    }

    /**
     * Bestellung mit allem, was für Prüfung und Preis gebraucht wird (Live-Version).
     *
     * @return array<string, mixed>|null
     */
    public function load(string $orderId): ?array
    {
        $orderId = strtolower($orderId);
        if (!Uuid::isValid($orderId)) {
            return null;
        }
        if (\array_key_exists($orderId, $this->memo)) {
            return $this->memo[$orderId];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(o.id)) AS id, o.order_number, o.order_date_time, LOWER(HEX(o.sales_channel_id)) AS sales_channel_id,
                    o.custom_fields, o.price, o.shipping_costs, o.currency_factor, os.technical_name AS order_state,
                    LOWER(HEX(oc.customer_id)) AS customer_id, oc.email, c.guest,
                    ds.technical_name AS delivery_state,
                    a.first_name, a.last_name, a.company, a.department, a.title, a.street, a.zipcode, a.city, a.phone_number,
                    a.additional_address_line1, a.additional_address_line2,
                    LOWER(HEX(a.country_id)) AS country_id, LOWER(HEX(a.country_state_id)) AS country_state_id,
                    LOWER(HEX(a.salutation_id)) AS salutation_id, co.iso AS country
               FROM `order` o
               INNER JOIN state_machine_state os ON os.id = o.state_id
               LEFT JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
               LEFT JOIN customer c ON c.id = oc.customer_id
               LEFT JOIN order_delivery d ON d.id = (
                    SELECT d2.id FROM order_delivery d2
                     WHERE d2.order_id = o.id AND d2.order_version_id = o.version_id AND d2.version_id = o.version_id
                     ORDER BY (d2.id = o.primary_order_delivery_id) DESC,
                              CAST(JSON_UNQUOTE(JSON_EXTRACT(d2.shipping_costs, \'$.totalPrice\')) AS DECIMAL(14,4)) DESC
                     LIMIT 1
               ) AND d.version_id = o.version_id
               LEFT JOIN state_machine_state ds ON ds.id = d.state_id
               LEFT JOIN order_address a ON a.id = d.shipping_order_address_id AND a.version_id = d.shipping_order_address_version_id
               LEFT JOIN country co ON co.id = a.country_id
              WHERE o.id = :id AND o.version_id = :live',
            ['id' => Uuid::fromHexToBytes($orderId), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if ($row === false) {
            return $this->memo[$orderId] = null;
        }

        $customFields = json_decode((string) ($row['custom_fields'] ?? ''), true);
        $row['customFields'] = \is_array($customFields) ? $customFields : [];
        $row['option'] = \is_string($row['customFields'][CF::ORDER_OPTION] ?? null) ? $row['customFields'][CF::ORDER_OPTION] : null;
        $row['isBeipack'] = !empty($row['customFields'][CF::ORDER_BEIPACK_TO]);
        $row['paidCents'] = self::paidShippingCents($row);
        try {
            $row['orderDate'] = new \DateTimeImmutable((string) $row['order_date_time'], new \DateTimeZone('UTC'));
        } catch (\Exception) {
            $row['orderDate'] = new \DateTimeImmutable('@0');
        }

        return $this->memo[$orderId] = $row;
    }

    /**
     * Bereits bezahlte Versandkosten in Cent (Euro, brutto bzw. steuerfrei wie die Bestellung).
     *
     * @param array<string, mixed> $row
     */
    public static function paidShippingCents(array $row): int
    {
        $costs = json_decode((string) ($row['shipping_costs'] ?? ''), true);
        $price = json_decode((string) ($row['price'] ?? ''), true);
        if (!\is_array($costs)) {
            return 0;
        }
        $total = (float) ($costs['totalPrice'] ?? 0);
        if (\is_array($price) && ($price['taxStatus'] ?? '') === 'net') {
            foreach ((array) ($costs['calculatedTaxes'] ?? []) as $tax) {
                $total += \is_array($tax) ? (float) ($tax['tax'] ?? 0) : 0.0;
            }
        }
        $factor = (float) ($row['currency_factor'] ?? 1) ?: 1.0;

        return (int) round($total / $factor * 100);
    }

    /**
     * Grund, warum nicht beigepackt werden kann – oder null.
     *
     * @param array<string, mixed> $row
     */
    public function ineligibleReason(array $row, SalesChannelContext $context, bool $checkCountry = true): ?string
    {
        return Beipack::ineligibleReason(
            [
                'orderState' => (string) $row['order_state'],
                'deliveryState' => (string) ($row['delivery_state'] ?? ''),
                'orderDate' => $row['orderDate'] instanceof \DateTimeImmutable ? $row['orderDate'] : new \DateTimeImmutable('@0'),
                'salesChannelId' => (string) $row['sales_channel_id'],
                'country' => (string) ($row['country'] ?? ''),
                'isBeipack' => (bool) $row['isBeipack'],
            ],
            $context->getSalesChannelId(),
            $checkCountry ? (string) $context->getShippingLocation()->getCountry()->getIso() : '',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $this->maxAgeDays($context->getSalesChannelId())
        );
    }

    /**
     * Gehört die Bestellung dem angemeldeten Kunden (gleiche Kunden-ID oder gleiche E-Mail)?
     *
     * @param array<string, mixed> $row
     */
    public static function isOwnedBy(array $row, SalesChannelContext $context): bool
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return false;
        }
        if ((string) ($row['customer_id'] ?? '') === strtolower($customer->getId())) {
            return true;
        }

        return !$customer->getGuest() && $customer->getEmail() !== ''
            && mb_strtolower(trim($customer->getEmail())) === mb_strtolower(trim((string) ($row['email'] ?? '')));
    }

    /**
     * Prüft die gewählte Bestellung vollständig (Besitz bei Wahl durch den Kunden, Status, Alter, Land).
     *
     * @param array{orderId: string, via: string} $selection
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} Bestellung, Fehlergrund
     */
    public function validate(array $selection, SalesChannelContext $context): array
    {
        $row = $this->load($selection['orderId']);
        if ($row === null) {
            return [null, 'Bestellung nicht gefunden'];
        }
        if ($selection['via'] === 'customer' && !self::isOwnedBy($row, $context)) {
            return [null, 'Bestellung gehört nicht zu diesem Kundenkonto'];
        }

        return [$row, $this->ineligibleReason($row, $context)];
    }

    /**
     * Offene, passende Bestellungen des angemeldeten Kunden (nur eigene; neueste zuerst, höchstens 20).
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(SalesChannelContext $context): array
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest()) {
            return [];
        }
        $days = $this->maxAgeDays($context->getSalesChannelId());
        $since = $days > 0 ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $days . ' days') : new \DateTimeImmutable('@0');

        $ids = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(o.id)) FROM `order` o
              INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
              WHERE oc.customer_id = :customer AND o.version_id = :live AND o.sales_channel_id = :sc
                AND o.order_date_time >= :since
              ORDER BY o.order_date_time DESC LIMIT 20',
            [
                'customer' => Uuid::fromHexToBytes($customer->getId()),
                'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'sc' => Uuid::fromHexToBytes($context->getSalesChannelId()),
                'since' => $since->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        $out = [];
        foreach ($ids as $id) {
            $row = $this->load((string) $id);
            if ($row !== null && $this->ineligibleReason($row, $context) === null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function findByDeepLinkCode(string $code, string $salesChannelId): ?string
    {
        if ($code === '' || \strlen($code) > 255) {
            return null;
        }
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM `order` WHERE deep_link_code = :code AND version_id = :live AND sales_channel_id = :sc',
            ['code' => $code, 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'sc' => Uuid::fromHexToBytes($salesChannelId)]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Bestellnummer + E-Mail + PLZ (Rechnungs- oder Lieferadresse) – alle drei müssen passen.
     */
    public function findByNumber(string $orderNumber, string $email, string $zipcode, string $salesChannelId): ?string
    {
        $orderNumber = trim($orderNumber);
        $email = mb_strtolower(trim($email));
        $zipcode = strtoupper(str_replace(' ', '', trim($zipcode)));
        if ($orderNumber === '' || $email === '' || $zipcode === '') {
            return null;
        }
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(o.id)) FROM `order` o
              INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
              WHERE o.order_number = :number AND o.version_id = :live AND o.sales_channel_id = :sc
                AND LOWER(oc.email) = :email
                AND EXISTS (SELECT 1 FROM order_address a WHERE a.order_id = o.id AND a.order_version_id = o.version_id
                             AND UPPER(REPLACE(a.zipcode, \' \', \'\')) = :zip)
              LIMIT 1',
            [
                'number' => $orderNumber,
                'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'sc' => Uuid::fromHexToBytes($salesChannelId),
                'email' => $email,
                'zip' => $zipcode,
            ]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Frühere Beipack-Bestellungen zu dieser Bestellung (nicht storniert): deren Artikel und Versand zählen mit.
     *
     * @return list<string>
     */
    public function beipackOrderIds(string $orderId): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(o.id)) FROM `order` o
              INNER JOIN state_machine_state os ON os.id = o.state_id
              WHERE o.version_id = :live AND os.technical_name <> \'cancelled\'
                AND JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, :path)) = :id',
            ['live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'path' => '$.' . CF::ORDER_BEIPACK_TO, 'id' => strtolower($orderId)]
        );

        return array_map('strval', $ids);
    }

    /**
     * Preis für „beipacken“: Rechenkern für alte (inkl. früherer Beipack-) und neue Artikel, Lieferadresse
     * der alten Bestellung, minus bereits bezahlter Versand.
     *
     * @param array<string, mixed> $row
     */
    public function quote(array $row, LineItemCollection $lineItems, SalesChannelContext $context): OptionResult
    {
        $salesChannelId = $context->getSalesChannelId();
        $orderIds = array_merge([(string) $row['id']], $this->beipackOrderIds((string) $row['id']));
        $paid = 0;
        foreach ($orderIds as $id) {
            $paid += $id === $row['id'] ? (int) $row['paidCents'] : (int) ($this->load($id)['paidCents'] ?? 0);
        }

        $items = array_merge(
            $this->classifier->itemsFromOrders($orderIds, $salesChannelId),
            $this->classifier->itemsFromCart($lineItems, $salesChannelId)
        );
        $result = $this->quotes->calculateItems(
            $items,
            (string) ($row['country'] ?? ''),
            (string) ($row['zipcode'] ?? ''),
            (string) ($row['company'] ?? ''),
            $salesChannelId
        );
        if ($result === null) {
            return OptionResult::unavailable(OptionResult::BEIPACK, 'keine Tarife');
        }

        $quote = Beipack::quote($result, \is_string($row['option'] ?? null) ? $row['option'] : null, $paid);
        $quote->meta += ['orderId' => $row['id'], 'orderNumber' => (string) $row['order_number']];

        return $quote;
    }

    /**
     * Lieferadresse der alten Bestellung als Adress-Array für die neue Bestellung (neue ID).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    public static function shippingAddress(array $row): array
    {
        $map = [
            'firstName' => 'first_name', 'lastName' => 'last_name', 'company' => 'company', 'department' => 'department',
            'title' => 'title', 'street' => 'street', 'zipcode' => 'zipcode', 'city' => 'city', 'phoneNumber' => 'phone_number',
            'additionalAddressLine1' => 'additional_address_line1', 'additionalAddressLine2' => 'additional_address_line2',
            'countryId' => 'country_id', 'countryStateId' => 'country_state_id', 'salutationId' => 'salutation_id',
        ];
        $address = [];
        foreach ($map as $key => $column) {
            $value = $row[$column] ?? null;
            if (\is_string($value) && $value !== '') {
                $address[$key] = $value;
            }
        }
        if ($address === [] || !isset($address['countryId'])) {
            return [];
        }
        $address['id'] = Uuid::randomHex();

        return $address;
    }
}
