<?php declare(strict_types=1);

namespace Nova\Versandkosten\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * Zusatzfelder:
 * - Produkt: „Versandeinordnung“ und „Sperrgut-Packart“ (Auswahlfelder)
 * - Bestellung: Ergebnis der Versandberechnung (Dienstleister, Produkt, Pakete, Einkauf, Verkauf, Maße fehlen)
 *
 * Fehlt ein Set, wird es angelegt; vorhandene Sets bleiben unverändert (Einstellungen des Inhabers).
 */
final class CustomFieldInstaller
{
    public const PRODUCT_SET = 'nova_versandkosten_product';
    public const ORDER_SET = 'nova_versandkosten_order';

    public const PRODUCT_CATEGORY = 'nova_versand_einordnung';
    public const PRODUCT_PACKING = 'nova_versand_packart';

    public const ORDER_CARRIER = 'nova_versandkosten_carrier';
    public const ORDER_PRODUCT = 'nova_versandkosten_product';
    public const ORDER_OPTION = 'nova_versandkosten_option';
    public const ORDER_PACKAGES = 'nova_versandkosten_packages';
    public const ORDER_PACKAGE_COUNT = 'nova_versandkosten_package_count';
    public const ORDER_PURCHASE = 'nova_versandkosten_purchase_net';
    public const ORDER_SALE = 'nova_versandkosten_sale';
    public const ORDER_SALE_NET = 'nova_versandkosten_sale_net';
    public const ORDER_DIMS_MISSING = 'nova_versandkosten_dims_missing';
    public const ORDER_DELIVERY = 'nova_versandkosten_delivery';
    public const ORDER_DETAILS = 'nova_versandkosten_details';

    /**
     * @param EntityRepository<\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection> $sets
     */
    public function __construct(
        private readonly EntityRepository $sets,
        private readonly Connection $connection,
    ) {
    }

    public function install(Context $context): void
    {
        $existing = $this->connection->fetchFirstColumn(
            'SELECT name FROM custom_field_set WHERE name IN (:names)',
            ['names' => [self::PRODUCT_SET, self::ORDER_SET]],
            ['names' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        $create = [];
        if (!\in_array(self::PRODUCT_SET, $existing, true)) {
            $create[] = $this->productSet();
        }
        if (!\in_array(self::ORDER_SET, $existing, true)) {
            $create[] = $this->orderSet();
        }
        if ($create !== []) {
            $this->sets->create($create, $context);
        }
    }

    public function uninstall(Context $context): void
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM custom_field_set WHERE name IN (:names)',
            ['names' => [self::PRODUCT_SET, self::ORDER_SET]],
            ['names' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        if ($ids !== []) {
            $this->sets->delete(array_map(static fn ($id) => ['id' => (string) $id], $ids), $context);
        }
    }

    /** @return array<string, mixed> */
    private function productSet(): array
    {
        return [
            'id' => Uuid::randomHex(),
            'name' => self::PRODUCT_SET,
            'config' => ['label' => ['de-DE' => 'Versand (Nova Versandkosten)', 'en-GB' => 'Shipping (Nova Versandkosten)'], 'translated' => true],
            'relations' => [['entityName' => 'product']],
            'customFields' => [
                [
                    'name' => self::PRODUCT_CATEGORY,
                    'type' => CustomFieldTypes::SELECT,
                    'config' => [
                        'label' => ['de-DE' => 'Versandeinordnung', 'en-GB' => 'Shipping category'],
                        'helpText' => [
                            'de-DE' => 'Leer = aus der Eigenschaft „Versandart“ ableiten (Einstellung des Plugins).',
                            'en-GB' => 'Empty = derive from the property “Versandart” (plugin setting).',
                        ],
                        'componentName' => 'sw-single-select',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 1,
                        'options' => [
                            ['value' => 'paket', 'label' => ['de-DE' => 'Paket', 'en-GB' => 'Parcel']],
                            ['value' => 'sperrgut', 'label' => ['de-DE' => 'Sperrgut', 'en-GB' => 'Bulky goods']],
                            ['value' => 'spedition', 'label' => ['de-DE' => 'Spedition', 'en-GB' => 'Freight']],
                            ['value' => 'abholung', 'label' => ['de-DE' => 'Nur Abholung', 'en-GB' => 'Collection only']],
                        ],
                    ],
                ],
                [
                    'name' => self::PRODUCT_PACKING,
                    'type' => CustomFieldTypes::SELECT,
                    'config' => [
                        'label' => ['de-DE' => 'Sperrgut-Packart', 'en-GB' => 'Bulky packing'],
                        'helpText' => [
                            'de-DE' => 'Einzelpaket: jedes Stück ein eigenes Paket (z. B. Kotflügel). Zusammen packbar: mehrere Stücke in ein Paket (z. B. Auspuffe).',
                            'en-GB' => 'Single parcel: each piece ships separately. Combinable: several pieces share one parcel.',
                        ],
                        'componentName' => 'sw-single-select',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 2,
                        'options' => [
                            ['value' => 'einzelpaket', 'label' => ['de-DE' => 'Einzelpaket', 'en-GB' => 'Single parcel']],
                            ['value' => 'zusammen', 'label' => ['de-DE' => 'Zusammen packbar', 'en-GB' => 'Combinable']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function orderSet(): array
    {
        $field = static fn (string $name, string $type, string $de, string $en, int $pos): array => [
            'name' => $name,
            'type' => $type,
            'config' => [
                'label' => ['de-DE' => $de, 'en-GB' => $en],
                'customFieldPosition' => $pos,
                'disabled' => true,
            ],
        ];

        return [
            'id' => Uuid::randomHex(),
            'name' => self::ORDER_SET,
            'config' => ['label' => ['de-DE' => 'Versandkosten (Nova)', 'en-GB' => 'Shipping costs (Nova)'], 'translated' => true],
            'relations' => [['entityName' => 'order']],
            'customFields' => [
                $field(self::ORDER_CARRIER, CustomFieldTypes::TEXT, 'Dienstleister', 'Carrier', 1),
                $field(self::ORDER_PRODUCT, CustomFieldTypes::TEXT, 'Versandprodukt', 'Shipping product', 2),
                $field(self::ORDER_PACKAGE_COUNT, CustomFieldTypes::INT, 'Anzahl Pakete', 'Number of parcels', 3),
                $field(self::ORDER_PURCHASE, CustomFieldTypes::FLOAT, 'Einkauf netto (geschätzt)', 'Purchase net (estimated)', 4),
                $field(self::ORDER_SALE, CustomFieldTypes::FLOAT, 'Verkauf (Versandkosten)', 'Sale (shipping costs)', 5),
                $field(self::ORDER_DIMS_MISSING, CustomFieldTypes::BOOL, 'Maße fehlen', 'Dimensions missing', 6),
                $field(self::ORDER_DELIVERY, CustomFieldTypes::TEXT, 'Zustellung voraussichtlich', 'Estimated delivery', 7),
            ],
        ];
    }
}
