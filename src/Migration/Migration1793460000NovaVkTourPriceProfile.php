<?php declare(strict_types=1);

namespace Nova\Versandkosten\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * 1.2.1 „Preisprofile“: der Tourpreis hängt davon ab, wer fährt. Neue Tabelle für die Profile und
 * Spalte `price_profile_id` am Tourtermin (leer = Standardprofil aus den Einstellungen).
 *
 * Bestehende Rollen „Touren“ (Betrachter/Bearbeiter/Löscher) erhalten die passenden Rechte.
 * Wird ein Profil gelöscht, fallen seine Termine auf das Standardprofil zurück (ON DELETE SET NULL).
 * Beim Deinstallieren ohne „Daten behalten“ entfernt das Plugin die Tabelle wieder.
 */
class Migration1793460000NovaVkTourPriceProfile extends MigrationStep
{
    public const TABLES = ['nova_vk_tour_price_profile'];

    public function getCreationTimestamp(): int
    {
        return 1793460000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nova_vk_tour_price_profile` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `small_price` INT NOT NULL DEFAULT 0,
                `small_max_km` DOUBLE NOT NULL DEFAULT 0,
                `freight_tiers` JSON NULL,
                `position` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `json.nova_vk_tour_price_profile.freight_tiers` CHECK (JSON_VALID(`freight_tiers`))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $column = $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => 'nova_vk_tour_date', 'c' => 'price_profile_id']
        );
        if ((int) $column === 0) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `nova_vk_tour_date`
                    ADD COLUMN `price_profile_id` BINARY(16) NULL AFTER `variant_id`,
                    ADD CONSTRAINT `fk.nova_vk_tour_date.price_profile_id` FOREIGN KEY (`price_profile_id`)
                        REFERENCES `nova_vk_tour_price_profile` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
                SQL);
        }

        // Bestehende Rollen „Touren“ um die Rechte für Preisprofile ergänzen
        $this->addAdditionalPrivileges($connection, [
            'nova_vk_tour.viewer' => ['nova_vk_tour_price_profile:read'],
            'nova_vk_tour.editor' => ['nova_vk_tour_price_profile:read', 'nova_vk_tour_price_profile:create', 'nova_vk_tour_price_profile:update'],
            'nova_vk_tour.deleter' => ['nova_vk_tour_price_profile:delete', 'nova_vk_tour_date:update'],
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
