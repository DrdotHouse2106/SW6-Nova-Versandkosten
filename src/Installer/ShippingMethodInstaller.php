<?php declare(strict_types=1);

namespace Nova\Versandkosten\Installer;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Service\MethodMap;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Legt je Option eine Versandart an – INAKTIV und keinem Verkaufskanal zugeordnet. Der Inhaber
 * aktiviert sie und ordnet sie den Verkaufskanälen zu. Vorhandene Versandarten (gleicher technischer
 * Name) werden nie verändert, damit eigene Namen, Bilder, Regeln und Zuordnungen erhalten bleiben.
 *
 * Jede Versandart bekommt einen Platzhalterpreis 0,00 €, weil Shopware ohne Preis die Versandart
 * sperrt. Den echten Preis setzt der Cart-Processor des Plugins.
 */
final class ShippingMethodInstaller
{
    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Shipping\ShippingMethodCollection> $shippingMethods
     */
    public function __construct(
        private readonly EntityRepository $shippingMethods,
        private readonly Connection $connection,
    ) {
    }

    public function install(Context $context): void
    {
        $existing = $this->connection->fetchFirstColumn(
            'SELECT technical_name FROM shipping_method WHERE technical_name LIKE :p',
            ['p' => MethodMap::PREFIX . '%']
        );
        $deliveryTimeId = $this->deliveryTimeId();
        $languages = $this->languagesByLocale();
        $position = 10;

        $create = [];
        foreach (MethodMap::METHODS as $option => [$nameDe, $nameEn]) {
            $technical = MethodMap::technicalName($option);
            $position += 10;
            if (\in_array($technical, $existing, true)) {
                continue;
            }
            $create[] = [
                'id' => Uuid::randomHex(),
                'technicalName' => $technical,
                'active' => false,
                'position' => $position,
                'taxType' => 'auto',
                'deliveryTimeId' => $deliveryTimeId,
                'name' => $nameDe,
                'translations' => $this->translations($languages, $nameDe, $nameEn),
                'prices' => [[
                    'id' => Uuid::randomHex(),
                    'calculation' => 1,
                    'quantityStart' => 0,
                    'currencyPrice' => [[
                        'currencyId' => Defaults::CURRENCY,
                        'gross' => 0.0,
                        'net' => 0.0,
                        'linked' => false,
                    ]],
                ]],
            ];
        }

        if ($create !== []) {
            $this->shippingMethods->create($create, $context);
        }
    }

    /**
     * Übersetzungen nur für vorhandene Sprachen (sonst lehnt Shopware das Anlegen ab).
     *
     * @param array<string, string> $languages Sprach-ID → Locale
     *
     * Ohne Beschreibung: Shopware zeigt sie in Kasse und Bestellbestätigung an.
     *
     * @return array<string, array{name: string}>
     */
    private function translations(array $languages, string $nameDe, string $nameEn): array
    {
        $out = [Defaults::LANGUAGE_SYSTEM => ['name' => $nameDe]];
        foreach ($languages as $id => $locale) {
            $out[$id] = ['name' => str_starts_with($locale, 'de') ? $nameDe : $nameEn];
        }

        return $out;
    }

    /** @return array<string, string> */
    private function languagesByLocale(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(language.id)), locale.code FROM language INNER JOIN locale ON locale.id = language.locale_id'
        );

        return $rows;
    }

    private function deliveryTimeId(): string
    {
        $id = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM delivery_time ORDER BY min ASC, max ASC LIMIT 1');
        if (\is_string($id) && $id !== '') {
            return $id;
        }

        $id = Uuid::randomHex();
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->insert('delivery_time', ['id' => Uuid::fromHexToBytes($id), 'min' => 1, 'max' => 3, 'unit' => 'day', 'created_at' => $now]);
        $languageId = $this->connection->fetchOne('SELECT id FROM language LIMIT 1');
        if ($languageId !== false) {
            $this->connection->insert('delivery_time_translation', ['delivery_time_id' => Uuid::fromHexToBytes($id), 'language_id' => $languageId, 'name' => '1-3 Tage', 'created_at' => $now]);
        }

        return $id;
    }
}
