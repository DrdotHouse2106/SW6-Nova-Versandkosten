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
 * - Bestellung: Ergebnis der Versandberechnung (Dienstleister, Produkt, Pakete, Verkauf, Maße fehlen),
 *   Beipacken, Versandkosten auf Anfrage und eigene Tour (Termin, Variante, Abweichung). Der Einkauf
 *   steht seit 1.4.0 nicht mehr in Zusatzfeldern (Store-API), sondern in der Tabelle nova_vk_order_cost.
 *
 * Fehlt ein Set, wird es angelegt. Bei vorhandenen Sets werden nur fehlende Felder ergänzt (Update auf
 * eine neue Version) und bei „Versandeinordnung“ fehlende Auswahlwerte angehängt; Bezeichnungen und
 * Reihenfolge, die der Inhaber geändert hat, bleiben unverändert.
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
    public const ORDER_SALE = 'nova_versandkosten_sale';
    public const ORDER_SALE_NET = 'nova_versandkosten_sale_net';
    public const ORDER_DIMS_MISSING = 'nova_versandkosten_dims_missing';
    public const ORDER_DELIVERY = 'nova_versandkosten_delivery';
    public const ORDER_DETAILS = 'nova_versandkosten_details';

    /** Beipack: an der neuen Bestellung – ID und Nummer der Bestellung, zu der beigepackt wird */
    public const ORDER_BEIPACK_TO = 'nova_versandkosten_beipack_zu';
    public const ORDER_BEIPACK_TO_NUMBER = 'nova_versandkosten_beipack_zu_nr';
    /** Beipack: an der alten Bestellung – Liste {id, orderNumber, date} der beigepackten Bestellungen */
    public const ORDER_BEIPACK_ORDERS = 'nova_versandkosten_beipack_bestellungen';

    /** Versandkosten auf Anfrage: Kennzeichen, Status (open|priced), Zeitpunkt der Preisangabe */
    public const ORDER_REQUEST = 'nova_versandkosten_anfrage';
    public const ORDER_REQUEST_STATUS = 'nova_versandkosten_anfrage_status';
    public const ORDER_REQUEST_PRICED_AT = 'nova_versandkosten_anfrage_preis_am';

    /** Eigene Tour: Termin-ID, Datum (Y-m-d), Variante (ID bzw. „custom“) und Name, Abweichung km, Einordnung */
    public const ORDER_TOUR_DATE_ID = 'nova_versandkosten_tour_termin';
    public const ORDER_TOUR_DATE = 'nova_versandkosten_tour_datum';
    public const ORDER_TOUR_VARIANT = 'nova_versandkosten_tour_variante';
    public const ORDER_TOUR_VARIANT_NAME = 'nova_versandkosten_tour_variante_name';
    public const ORDER_TOUR_DEVIATION = 'nova_versandkosten_tour_abweichung_km';
    public const ORDER_TOUR_TIER = 'nova_versandkosten_tour_einordnung';

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
        /** @var array<string, string> $existing Name → ID */
        $existing = $this->connection->fetchAllKeyValue(
            'SELECT name, LOWER(HEX(id)) FROM custom_field_set WHERE name IN (:names)',
            ['names' => [self::PRODUCT_SET, self::ORDER_SET]],
            ['names' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        $create = [];
        $update = [];
        foreach ([self::PRODUCT_SET => $this->productSet(), self::ORDER_SET => $this->orderSet()] as $name => $set) {
            if (!isset($existing[$name])) {
                $create[] = $set;
                continue;
            }
            $missing = $this->missingFields($set['customFields']);
            if ($missing !== []) {
                $update[] = ['id' => $existing[$name], 'customFields' => $missing];
            }
        }
        if ($create !== []) {
            $this->sets->create($create, $context);
        }
        if ($update !== []) {
            $this->sets->upsert($update, $context);
        }

        $this->addMissingOptions(self::PRODUCT_CATEGORY, $this->productSet()['customFields'][0]['config']['options']);
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function missingFields(array $fields): array
    {
        $names = array_map(static fn (array $f) => (string) $f['name'], $fields);
        $present = $this->connection->fetchFirstColumn(
            'SELECT name FROM custom_field WHERE name IN (:names)',
            ['names' => $names],
            ['names' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        return array_values(array_filter($fields, static fn (array $f) => !\in_array($f['name'], $present, true)));
    }

    /**
     * Neue Auswahlwerte (z. B. „Nur auf Anfrage“ ab 1.1.0) an ein vorhandenes Auswahlfeld anhängen.
     *
     * @param list<array{value: string, label: array<string, string>}> $options
     */
    private function addMissingOptions(string $fieldName, array $options): void
    {
        $config = $this->connection->fetchOne('SELECT config FROM custom_field WHERE name = :name', ['name' => $fieldName]);
        if (!\is_string($config)) {
            return;
        }
        $data = json_decode($config, true);
        if (!\is_array($data) || !\is_array($data['options'] ?? null)) {
            return;
        }
        $values = array_map(static fn ($o) => \is_array($o) ? ($o['value'] ?? null) : null, $data['options']);
        $changed = false;
        foreach ($options as $option) {
            if (!\in_array($option['value'], $values, true)) {
                $data['options'][] = $option;
                $changed = true;
            }
        }
        if ($changed) {
            $this->connection->executeStatement(
                'UPDATE custom_field SET config = :config, updated_at = NOW(3) WHERE name = :name',
                ['config' => json_encode($data, \JSON_UNESCAPED_UNICODE), 'name' => $fieldName]
            );
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

    /** @return array{id: string, name: string, config: array<string, mixed>, relations: list<array<string, string>>, customFields: list<array{name: string, type: string, config: array<string, mixed>}>} */
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
                            ['value' => 'anfrage', 'label' => ['de-DE' => 'Nur auf Anfrage', 'en-GB' => 'On request only']],
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

    /** @return array{id: string, name: string, config: array<string, mixed>, relations: list<array<string, string>>, customFields: list<array{name: string, type: string, config: array<string, mixed>}>} */
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
                $field(self::ORDER_SALE, CustomFieldTypes::FLOAT, 'Verkauf (Versandkosten)', 'Sale (shipping costs)', 5),
                $field(self::ORDER_DIMS_MISSING, CustomFieldTypes::BOOL, 'Maße fehlen', 'Dimensions missing', 6),
                $field(self::ORDER_DELIVERY, CustomFieldTypes::TEXT, 'Zustellung voraussichtlich', 'Estimated delivery', 7),
                $field(self::ORDER_BEIPACK_TO_NUMBER, CustomFieldTypes::TEXT, 'Beipack zu Bestellung', 'Add-on to order', 8),
                $field(self::ORDER_REQUEST, CustomFieldTypes::BOOL, 'Versandkosten auf Anfrage', 'Shipping costs on request', 9),
                $field(self::ORDER_REQUEST_STATUS, CustomFieldTypes::TEXT, 'Anfrage-Status (open/priced)', 'Request status (open/priced)', 10),
                $field(self::ORDER_TOUR_DATE, CustomFieldTypes::TEXT, 'Tour: Datum', 'Tour: date', 11),
                $field(self::ORDER_TOUR_DATE_ID, CustomFieldTypes::TEXT, 'Tour: Termin-ID', 'Tour: date ID', 12),
                $field(self::ORDER_TOUR_VARIANT_NAME, CustomFieldTypes::TEXT, 'Tour: Variante', 'Tour: variant', 13),
                $field(self::ORDER_TOUR_DEVIATION, CustomFieldTypes::FLOAT, 'Tour: Abweichung (km)', 'Tour: deviation (km)', 14),
                $field(self::ORDER_TOUR_TIER, CustomFieldTypes::TEXT, 'Tour: Einordnung (klein/spedition)', 'Tour: category (klein/spedition)', 15),
            ],
        ];
    }
}
