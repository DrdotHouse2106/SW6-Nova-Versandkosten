<?php declare(strict_types=1);

namespace Nova\Versandkosten\Migration;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\OrderCostSplit;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * 1.4.0 (Sicherheit): Einkauf nicht mehr in order.customFields.
 *
 * Zusatzfelder liefert die Store-API dem Kunden mit (GET /store-api/order). Der geschätzte Einkauf,
 * der Einkauf je Paket und die Cargoboard-Angebots-ID wandern deshalb in die eigene Tabelle
 * `nova_vk_order_cost` (keine DAL-Entität, nur über die Admin-Route der Bestellkarte lesbar).
 *
 * Bestandsdaten: Einkauf der Live-Version in die Tabelle übernehmen, dann aus den Zusatzfeldern aller
 * Versionen entfernen. Maße, Gewichte, Pakete, Verkauf und „Maße fehlen“ bleiben in den Zusatzfeldern.
 * Idempotent: ein zweiter Lauf findet nichts mehr. Das Zusatzfeld „Einkauf netto (geschätzt)“ wird aus
 * dem Set entfernt. Beim Deinstallieren ohne „Daten behalten“ entfernt das Plugin die Tabelle.
 */
class Migration1794000000NovaVkOrderCost extends MigrationStep
{
    public const TABLES = ['nova_vk_order_cost'];

    private const BATCH = 500;

    public function getCreationTimestamp(): int
    {
        return 1794000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nova_vk_order_cost` (
                `order_id` BINARY(16) NOT NULL,
                `order_version_id` BINARY(16) NOT NULL,
                `purchase_net` DECIMAL(12,2) NOT NULL DEFAULT 0,
                `data` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`order_id`, `order_version_id`),
                CONSTRAINT `json.nova_vk_order_cost.data` CHECK (JSON_VALID(`data`)),
                CONSTRAINT `fk.nova_vk_order_cost.order_id` FOREIGN KEY (`order_id`, `order_version_id`)
                    REFERENCES `order` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $this->moveExisting($connection);

        $connection->executeStatement(
            'DELETE FROM custom_field WHERE name = :name',
            ['name' => OrderCostSplit::FIELD_PURCHASE]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function moveExisting(Connection $connection): void
    {
        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $lastId = '';
        $lastVersion = '';

        while (true) {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, version_id, custom_fields FROM `order`
                  WHERE (id > :lastId OR (id = :lastId AND version_id > :lastVersion))
                    AND custom_fields IS NOT NULL
                    AND (JSON_CONTAINS_PATH(custom_fields, \'one\', :purchase, :details)
                         OR JSON_EXTRACT(custom_fields, :packageKeys) IS NOT NULL)
                  ORDER BY id, version_id
                  LIMIT ' . self::BATCH,
                [
                    'lastId' => $lastId,
                    'lastVersion' => $lastVersion,
                    'purchase' => '$.' . OrderCostSplit::FIELD_PURCHASE,
                    'details' => '$.' . OrderCostSplit::FIELD_DETAILS . '.freightQuotationId',
                    'packageKeys' => '$.' . OrderCostSplit::FIELD_PACKAGES . '[*].purchaseNet',
                ]
            );
            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = (string) $row['id'];
                $lastVersion = (string) $row['version_id'];
                $customFields = json_decode((string) $row['custom_fields'], true);
                if (!\is_array($customFields)) {
                    continue;
                }
                [$clean, $cost, $changed] = OrderCostSplit::split($customFields);
                if ($cost !== null && $lastVersion === $live) {
                    $connection->executeStatement(
                        'INSERT IGNORE INTO `nova_vk_order_cost` (order_id, order_version_id, purchase_net, data, created_at)
                         VALUES (:id, :version, :purchase, :data, :now)',
                        [
                            'id' => $lastId,
                            'version' => $lastVersion,
                            'purchase' => round($cost['purchaseNet'], 2),
                            'data' => json_encode($cost['data'], \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE),
                            'now' => $now,
                        ]
                    );
                }
                if ($changed) {
                    $connection->executeStatement(
                        'UPDATE `order` SET custom_fields = :cf WHERE id = :id AND version_id = :version',
                        [
                            'cf' => json_encode($clean, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE),
                            'id' => $lastId,
                            'version' => $lastVersion,
                        ]
                    );
                }
            }
        }
    }
}
