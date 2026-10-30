<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Installer\CustomFieldInstaller;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
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
     * @return list<Item>
     */
    public function itemsFromCart(LineItemCollection $lineItems, ?string $salesChannelId): array
    {
        $products = [];
        foreach ($lineItems->getFlat() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE || $lineItem->getDeliveryInformation() === null) {
                continue;
            }
            $products[] = $lineItem;
        }

        $optionIds = [];
        foreach ($products as $lineItem) {
            foreach ((array) $lineItem->getPayloadValue('propertyIds') as $id) {
                $optionIds[] = (string) $id;
            }
        }
        $this->loadOptionNames($optionIds);

        $freeManufacturers = $this->settings->ids('freeManufacturerIds', $salesChannelId);
        $freeTags = $this->settings->ids('freeTagIds', $salesChannelId);

        $items = [];
        foreach ($products as $lineItem) {
            $info = $lineItem->getDeliveryInformation();
            if ($info === null) {
                continue;
            }
            $customFields = $lineItem->getPayloadValue('customFields');
            [$category, $packing] = $this->classify(\is_array($customFields) ? $customFields : [], (array) $lineItem->getPayloadValue('propertyIds'), $salesChannelId);

            $manufacturer = strtolower((string) $lineItem->getPayloadValue('manufacturerId'));
            $tags = array_map(static fn ($t) => strtolower((string) $t), (array) $lineItem->getPayloadValue('tagIds'));
            $free = ($manufacturer !== '' && \in_array($manufacturer, $freeManufacturers, true))
                || array_intersect($tags, $freeTags) !== [];

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
            );
        }

        return $items;
    }

    /**
     * @param array<mixed> $customFields
     * @param array<mixed> $propertyIds
     *
     * @return array{0: string, 1: string}
     */
    public function classify(array $customFields, array $propertyIds, ?string $salesChannelId): array
    {
        $packing = (string) ($customFields[CustomFieldInstaller::PRODUCT_PACKING] ?? '');
        if (!\in_array($packing, [Item::PACK_SINGLE, Item::PACK_COMBINABLE], true)) {
            $packing = Item::PACK_SINGLE;
        }

        $category = (string) ($customFields[CustomFieldInstaller::PRODUCT_CATEGORY] ?? '');
        if (\in_array($category, Item::CATEGORIES, true)) {
            return [$category, $packing];
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
        if ($values === []) {
            return [Item::PARCEL, $packing];
        }

        $match = function (string $key) use ($values, $salesChannelId): bool {
            $configured = array_map('mb_strtolower', $this->settings->lines($key, $salesChannelId));

            return array_intersect($values, $configured) !== [];
        };

        if ($match('valuesParcel')) {
            return [Item::PARCEL, $packing];
        }
        if ($match('valuesBulky')) {
            return [Item::BULKY, $packing];
        }
        if ($match('valuesFreight')) {
            return [Item::FREIGHT, $packing];
        }
        if ($match('valuesPickup')) {
            return [Item::PICKUP_ONLY, $packing];
        }

        return [Item::PARCEL, $packing];
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
