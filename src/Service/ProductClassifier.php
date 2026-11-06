<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Installer\CustomFieldInstaller;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Macht aus Warenkorbpositionen bzw. Produkten die Eingabe des Rechenkerns.
 *
 * Einordnung: Zusatzfeld „Versandeinordnung“; fehlt es, aus der Eigenschaft (Standard „Versandart“):
 * normaler Wert „DHL“/„DPD“ → Paket, sonst „… Sperrgut“ → Sperrgut, sonst „Spedition“/„Kurier“ →
 * Spedition, nur „Abholung“ → nur Abholung (Wertnamen einstellbar). Ohne Angabe: Paket.
 * Maße: Shopware-Produktfelder Länge/Breite/Höhe in mm (→ cm), Gewicht in kg.
 */
class ProductClassifier implements ResetInterface
{
    /** @var array<string, list<array{group: string, name: string}>> Options-ID → Gruppe/Wert je Sprache (Kleinbuchstaben) */
    private array $optionNames = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly Settings $settings,
    ) {
    }

    public function reset(): void
    {
        $this->optionNames = [];
    }

    /**
     * Warenkorbpositionen → Eingabe des Rechenkerns.
     *
     * Gewicht und Maße kommen aus der Lieferinformation (setzt Shopware aus den Produktdaten). Einordnung,
     * Packart, Hersteller und Tags („versandkostenfrei“) werden seit 1.4.0 aus der Datenbank gelesen und
     * NICHT aus dem Positions-Payload – den kann ein Kunde über die Store-API mitschicken.
     *
     * Berücksichtigt werden alle versandkostenrelevanten Positionen mit Lieferinformation (wie Shopwares
     * DeliveryBuilder: hat eine Position keine, zählen ihre Unterpositionen) – nicht nur Typ „product“.
     *
     * @return list<Item>
     */
    public function itemsFromCart(LineItemCollection $lineItems, ?string $salesChannelId, ?string $languageId = null): array
    {
        $positions = [];
        self::collectDeliverable($lineItems, $positions);

        $productIds = [];
        foreach ($positions as $lineItem) {
            $id = strtolower((string) $lineItem->getReferencedId());
            if (Uuid::isValid($id)) {
                $productIds[$id] = $id;
            }
        }
        $products = $this->loadProducts(array_values($productIds), $languageId);

        $optionIds = [];
        foreach ($products as $product) {
            foreach ($product['propertyIds'] as $id) {
                $optionIds[] = $id;
            }
        }
        $this->loadOptionNames($optionIds);

        $freeManufacturers = $this->settings->ids('freeManufacturerIds', $salesChannelId);
        $freeTags = $this->settings->ids('freeTagIds', $salesChannelId);

        $items = [];
        foreach ($positions as $lineItem) {
            $info = $lineItem->getDeliveryInformation();
            if ($info === null) {
                continue;
            }
            $product = $products[strtolower((string) $lineItem->getReferencedId())] ?? null;
            if ($product !== null) {
                [$category, $packing, $smallAllowed, $carriers] = $this->classify($product['customFields'], $product['propertyIds'], $salesChannelId);
                $free = self::isFree($product['manufacturerId'], $product['tagIds'], $freeManufacturers, $freeTags);
            } else {
                // Keine Produktdaten (fremder Positionstyp): Standardeinordnung, nie versandkostenfrei
                [$category, $packing, $smallAllowed, $carriers] = [Item::PARCEL, Item::PACK_SINGLE, true, null];
                $free = false;
            }

            $release = $lineItem->getPayloadValue('releaseDate');
            $availableFrom = null;
            if (\is_string($release) && $release !== '') {
                try {
                    $date = new \DateTimeImmutable($release);
                    $availableFrom = $date > new \DateTimeImmutable() ? $date : null;
                } catch (\Exception) {
                }
            }

            $unitPrice = $lineItem->getPrice()?->getUnitPrice() ?? 0.0;

            $items[] = new Item(
                (string) ($lineItem->getReferencedId() ?? $lineItem->getId()),
                (string) ($lineItem->getLabel() ?? $lineItem->getPayloadValue('productNumber') ?? ''),
                $info->getWeight(),
                self::mmToCm($info->getLength()),
                self::mmToCm($info->getWidth()),
                self::mmToCm($info->getHeight()),
                $category,
                $packing,
                $lineItem->getQuantity(),
                $unitPrice,
                $free,
                $availableFrom,
                $smallAllowed,
                $carriers,
            );
        }

        return $items;
    }

    /**
     * Positionen mit Lieferinformation (Reihenfolge und Logik wie Shopwares DeliveryBuilder).
     *
     * @param list<LineItem> $out
     */
    private static function collectDeliverable(LineItemCollection $lineItems, array &$out): void
    {
        foreach ($lineItems as $lineItem) {
            if (!$lineItem->isShippingCostAware()) {
                continue;
            }
            if ($lineItem->getDeliveryInformation() === null) {
                if ($lineItem->getChildren()->count() > 0) {
                    self::collectDeliverable($lineItem->getChildren(), $out);
                }
                continue;
            }
            $out[] = $lineItem;
        }
    }

    /**
     * @param list<string> $tags
     * @param list<string> $freeManufacturers
     * @param list<string> $freeTags
     */
    public static function isFree(string $manufacturerId, array $tags, array $freeManufacturers, array $freeTags): bool
    {
        $manufacturerId = strtolower($manufacturerId);
        $tags = array_map('strtolower', $tags);

        return ($manufacturerId !== '' && \in_array($manufacturerId, array_map('strtolower', $freeManufacturers), true))
            || array_intersect($tags, array_map('strtolower', $freeTags)) !== [];
    }

    /**
     * Versandrelevante Produktdaten aus der Datenbank (Live-Version; Variante erbt vom Elternartikel).
     *
     * @param list<string> $ids
     *
     * @return array<string, array{customFields: array<string, mixed>, propertyIds: list<string>, manufacturerId: string, tagIds: list<string>}>
     */
    private function loadProducts(array $ids, ?string $languageId): array
    {
        if ($ids === []) {
            return [];
        }
        $languageId = $languageId !== null && Uuid::isValid($languageId) ? $languageId : Defaults::LANGUAGE_SYSTEM;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(p.id)) AS id,
                    COALESCE(p.property_ids, pp.property_ids) AS property_ids,
                    LOWER(HEX(COALESCE(p.product_manufacturer_id, pp.product_manufacturer_id))) AS manufacturer_id,
                    COALESCE(p.tag_ids, pp.tag_ids) AS tag_ids,
                    ppt.custom_fields AS cf_parent_sys, pptl.custom_fields AS cf_parent_lang,
                    pt.custom_fields AS cf_sys, ptl.custom_fields AS cf_lang
               FROM product p
               LEFT JOIN product pp ON pp.id = p.parent_id AND pp.version_id = :live
               LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = :live AND pt.language_id = :sys
               LEFT JOIN product_translation ptl ON ptl.product_id = p.id AND ptl.product_version_id = :live AND ptl.language_id = :lang
               LEFT JOIN product_translation ppt ON ppt.product_id = pp.id AND ppt.product_version_id = :live AND ppt.language_id = :sys
               LEFT JOIN product_translation pptl ON pptl.product_id = pp.id AND pptl.product_version_id = :live AND pptl.language_id = :lang
              WHERE p.id IN (:ids) AND p.version_id = :live',
            [
                'ids' => array_map(static fn (string $id) => Uuid::fromHexToBytes($id), $ids),
                'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'sys' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'lang' => Uuid::fromHexToBytes($languageId),
            ],
            ['ids' => ArrayParameterType::BINARY]
        );

        $products = [];
        foreach ($rows as $row) {
            $customFields = [];
            // Vorrang: Variante (Sprache, System) vor Elternartikel (Sprache, System)
            foreach (['cf_parent_sys', 'cf_parent_lang', 'cf_sys', 'cf_lang'] as $column) {
                $decoded = json_decode((string) ($row[$column] ?? ''), true);
                if (\is_array($decoded)) {
                    $customFields = array_replace($customFields, array_filter($decoded, static fn ($v) => $v !== null && $v !== ''));
                }
            }
            $products[(string) $row['id']] = [
                'customFields' => $customFields,
                'propertyIds' => self::idList($row['property_ids'] ?? null),
                'manufacturerId' => (string) ($row['manufacturer_id'] ?? ''),
                'tagIds' => self::idList($row['tag_ids'] ?? null),
            ];
        }

        return $products;
    }

    /**
     * @return list<string>
     */
    private static function idList(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($id) => strtolower((string) $id), $decoded), static fn (string $id) => Uuid::isValid($id)));
    }

    /**
     * Artikel bereits bestellter (noch nicht versendeter) Bestellungen – für „Zur Bestellung beipacken“.
     * Maße/Gewicht, Zusatzfelder, Eigenschaften, Hersteller und Tags kommen vom Produkt (Elternartikel als
     * Ersatz); nur bei gelöschten Produkten aus der Bestellposition (Standardmaße).
     *
     * @param list<string> $orderIds
     *
     * @return list<Item>
     */
    public function itemsFromOrders(array $orderIds, ?string $salesChannelId): array
    {
        $orderIds = array_values(array_filter($orderIds, static fn (string $id) => Uuid::isValid($id)));
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(li.id)) AS id, LOWER(HEX(li.product_id)) AS product_id, li.label, li.quantity, li.unit_price, li.payload,
                    COALESCE(p.weight, pp.weight) AS weight, COALESCE(p.length, pp.length) AS length,
                    COALESCE(p.width, pp.width) AS width, COALESCE(p.height, pp.height) AS height,
                    COALESCE(pt.custom_fields, ppt.custom_fields) AS custom_fields,
                    p.id IS NOT NULL AS product_exists,
                    COALESCE(p.property_ids, pp.property_ids) AS property_ids,
                    LOWER(HEX(COALESCE(p.product_manufacturer_id, pp.product_manufacturer_id))) AS manufacturer_id,
                    COALESCE(p.tag_ids, pp.tag_ids) AS tag_ids
               FROM order_line_item li
               LEFT JOIN product p ON p.id = li.product_id AND p.version_id = :live
               LEFT JOIN product pp ON pp.id = p.parent_id AND pp.version_id = :live
               LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = :live AND pt.language_id = :lang
               LEFT JOIN product_translation ppt ON ppt.product_id = pp.id AND ppt.product_version_id = :live AND ppt.language_id = :lang
              WHERE li.order_id IN (:ids) AND li.version_id = :live AND li.order_version_id = :live AND li.type = :type',
            [
                'ids' => array_map(static fn (string $id) => Uuid::fromHexToBytes($id), $orderIds),
                'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'lang' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            ],
            ['ids' => ArrayParameterType::BINARY]
        );

        $freeManufacturers = $this->settings->ids('freeManufacturerIds', $salesChannelId);
        $freeTags = $this->settings->ids('freeTagIds', $salesChannelId);

        $items = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $payload = \is_array($payload) ? $payload : [];
            $customFields = json_decode((string) ($row['custom_fields'] ?? ''), true);
            if (!\is_array($customFields)) {
                $customFields = empty($row['product_exists']) && \is_array($payload['customFields'] ?? null) ? $payload['customFields'] : [];
            }
            // Produkt vorhanden: Eigenschaften, Hersteller, Tags aus der Datenbank (nicht aus dem Payload)
            if (!empty($row['product_exists'])) {
                $propertyIds = self::idList($row['property_ids'] ?? null);
                $manufacturerId = (string) ($row['manufacturer_id'] ?? '');
                $tagIds = self::idList($row['tag_ids'] ?? null);
            } else {
                $propertyIds = array_values(array_map('strval', (array) ($payload['propertyIds'] ?? [])));
                $manufacturerId = (string) ($payload['manufacturerId'] ?? '');
                $tagIds = array_values(array_map('strval', (array) ($payload['tagIds'] ?? [])));
            }
            [$category, $packing, $smallAllowed, $carriers] = $this->classify($customFields, $propertyIds, $salesChannelId);

            $free = self::isFree($manufacturerId, $tagIds, $freeManufacturers, $freeTags);

            $num = static fn (mixed $v): ?float => is_numeric($v) && (float) $v > 0 ? (float) $v : null;

            $items[] = new Item(
                (string) ($row['product_id'] ?: $row['id']),
                (string) ($row['label'] ?? ''),
                $num($row['weight'] ?? null),
                self::mmToCm($num($row['length'] ?? null)),
                self::mmToCm($num($row['width'] ?? null)),
                self::mmToCm($num($row['height'] ?? null)),
                $category,
                $packing,
                max(1, (int) $row['quantity']),
                (float) $row['unit_price'],
                $free,
                null,
                $smallAllowed,
                $carriers,
            );
        }

        return $items;
    }

    /**
     * @param array<mixed> $customFields
     * @param array<mixed> $propertyIds
     *
     * Einordnung, Packart und ob Warenpost/Kleinpaket erlaubt sind. Steht in der Eigenschaft „Versandart“
     * mindestens ein Wert, aber keiner aus „Werte für Warenpost/Kleinpaket“, sind beide gesperrt.
     * Nennen Werte einen Paketdienst („DHL Sperrgut“, „DPD Paket“), sind nur diese Paketdienste erlaubt
     * (Einstellung „Paketdienst aus Versandart“); Werte ohne Paketdienst (Kurier, Warenpost) schränken nicht ein.
     *
     * @return array{0: string, 1: string, 2: bool, 3: list<string>|null}
     */
    public function classify(array $customFields, array $propertyIds, ?string $salesChannelId): array
    {
        $packing = (string) ($customFields[CustomFieldInstaller::PRODUCT_PACKING] ?? '');
        if (!\in_array($packing, [Item::PACK_SINGLE, Item::PACK_COMBINABLE], true)) {
            $packing = Item::PACK_SINGLE;
        }

        $this->loadOptionNames(array_values(array_map('strval', $propertyIds)));
        $group = mb_strtolower($this->settings->string('propertyGroupName', $salesChannelId, 'Versandart'));
        $values = [];
        foreach ($propertyIds as $id) {
            foreach ($this->optionNames[strtolower((string) $id)] ?? [] as $option) {
                if ($group === '' || $option['group'] === $group) {
                    $values[] = $option['name'];
                }
            }
        }

        $match = function (string $key) use ($values, $salesChannelId): bool {
            $configured = array_map('mb_strtolower', $this->settings->lines($key, $salesChannelId));

            return array_intersect($values, $configured) !== [];
        };

        // Leere Liste = Regel aus
        $smallAllowed = $values === [] || $this->settings->lines('valuesSmall', $salesChannelId) === [] || $match('valuesSmall');
        $carriers = $this->settings->bool('carrierFromValues', $salesChannelId, true) ? Item::carriersFromValues($values) : null;

        $category = (string) ($customFields[CustomFieldInstaller::PRODUCT_CATEGORY] ?? '');
        if (\in_array($category, Item::CATEGORIES, true)) {
            return [$category, $packing, $smallAllowed, $carriers];
        }
        if ($values === []) {
            return [Item::PARCEL, $packing, true, null];
        }

        foreach ([
            'valuesParcel' => Item::PARCEL,
            'valuesBulky' => Item::BULKY,
            'valuesFreight' => Item::FREIGHT,
            'valuesPickup' => Item::PICKUP_ONLY,
            'valuesRequest' => Item::ON_REQUEST,
        ] as $key => $result) {
            if ($match($key)) {
                return [$result, $packing, $smallAllowed, $carriers];
            }
        }

        return [Item::PARCEL, $packing, $smallAllowed, $carriers];
    }

    public static function mmToCm(?float $mm): ?float
    {
        return $mm === null || $mm <= 0 ? null : $mm / 10;
    }

    /**
     * @param list<string> $ids
     */
    private function loadOptionNames(array $ids): void
    {
        $missing = [];
        foreach ($ids as $id) {
            $id = strtolower($id);
            if (Uuid::isValid($id) && !isset($this->optionNames[$id])) {
                $missing[$id] = $id;
            }
        }
        if ($missing === []) {
            return;
        }

        // Alle Übersetzungen: passt der Name in irgendeiner Sprache, zählt er.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(o.id)) AS id, ot.name AS name, gt.name AS grp
               FROM property_group_option o
               INNER JOIN property_group_option_translation ot ON ot.property_group_option_id = o.id
               INNER JOIN property_group_translation gt ON gt.property_group_id = o.property_group_id AND gt.language_id = ot.language_id
              WHERE o.id IN (:ids)',
            ['ids' => array_map(static fn (string $id) => Uuid::fromHexToBytes($id), array_values($missing))],
            ['ids' => ArrayParameterType::BINARY]
        );

        foreach ($missing as $id) {
            $this->optionNames[$id] = [];
        }
        foreach ($rows as $row) {
            $this->optionNames[(string) $row['id']][] = [
                'group' => mb_strtolower(trim((string) $row['grp'])),
                'name' => mb_strtolower(trim((string) $row['name'])),
            ];
        }
    }
}
