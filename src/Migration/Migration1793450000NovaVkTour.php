<?php declare(strict_types=1);

namespace Nova\Versandkosten\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * 1.2.0 „Eigene Touren“: Strecken mit Varianten, Tourtermine, Abonnenten der Tour-Benachrichtigung,
 * Versandprotokoll der Benachrichtigungen und die Postleitzahl-Koordinaten (GeoNames).
 *
 * Beim Deinstallieren ohne „Daten behalten“ entfernt das Plugin die Tabellen wieder.
 */
class Migration1793450000NovaVkTour extends MigrationStep
{
    public const TABLES = [
        'nova_vk_tour_notification',
        'nova_vk_tour_subscriber',
        'nova_vk_tour_date',
        'nova_vk_tour_variant',
        'nova_vk_tour_route',
        'nova_vk_postcode',
    ];

    public function getCreationTimestamp(): int
    {
        return 1793450000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nova_vk_tour_route` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `start_label` VARCHAR(255) NULL,
                `end_label` VARCHAR(255) NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `note` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nova_vk_tour_variant` (
                `id` BINARY(16) NOT NULL,
                `route_id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `position` INT NOT NULL DEFAULT 0,
                `waypoints` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `json.nova_vk_tour_variant.waypoints` CHECK (JSON_VALID(`waypoints`)),
                CONSTRAINT `fk.nova_vk_tour_variant.route_id` FOREIGN KEY (`route_id`)
                    REFERENCES `nova_vk_tour_route` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nova_vk_tour_date` (
                `id` BINARY(16) NOT NULL,
                `route_id` BINARY(16) NULL,
                `variant_id` BINARY(16) NULL,
                `name` VARCHAR(255) NULL,
                `tour_date` DATE NOT NULL,
                `order_deadline` DATE NOT NULL,
                `trailer` TINYINT(1) NOT NULL DEFAULT 0,
                `status` VARCHAR(20) NOT NULL DEFAULT 'open',
                `waypoints` JSON NULL,
                `note` LONGTEXT NULL,
                `notified_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.nova_vk_tour_date.status_date` (`status`, `tour_date`),
                CONSTRAINT `json.nova_vk_tour_date.waypoints` CHECK (JSON_VALID(`waypoints`)),
                CONSTRAINT `fk.nova_vk_tour_date.route_id` FOREIGN KEY (`route_id`)
                    REFERENCES `nova_vk_tour_route` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.nova_vk_tour_date.variant_id` FOREIGN KEY (`variant_id`)
                    REFERENCES `nova_vk_tour_variant` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nova_vk_tour_subscriber` (
                `id` BINARY(16) NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `postcode` VARCHAR(20) NOT NULL,
                `country` VARCHAR(2) NOT NULL DEFAULT 'DE',
                `place` VARCHAR(255) NULL,
                `latitude` DOUBLE NULL,
                `longitude` DOUBLE NULL,
                `customer_id` BINARY(16) NULL,
                `customer_address_id` BINARY(16) NULL,
                `sales_channel_id` BINARY(16) NULL,
                `language_id` BINARY(16) NULL,
                `source` VARCHAR(30) NOT NULL DEFAULT 'form',
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `doi_token_hash` VARCHAR(64) NULL,
                `confirmed_at` DATETIME(3) NULL,
                `last_activity_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.nova_vk_tour_subscriber.address` (`customer_address_id`),
                KEY `idx.nova_vk_tour_subscriber.status` (`status`, `last_activity_at`),
                KEY `idx.nova_vk_tour_subscriber.email` (`email`),
                KEY `idx.nova_vk_tour_subscriber.doi` (`doi_token_hash`),
                CONSTRAINT `fk.nova_vk_tour_subscriber.customer_id` FOREIGN KEY (`customer_id`)
                    REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.nova_vk_tour_subscriber.customer_address_id` FOREIGN KEY (`customer_address_id`)
                    REFERENCES `customer_address` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.nova_vk_tour_subscriber.sales_channel_id` FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.nova_vk_tour_subscriber.language_id` FOREIGN KEY (`language_id`)
                    REFERENCES `language` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nova_vk_tour_notification` (
                `tour_date_id` BINARY(16) NOT NULL,
                `subscriber_id` BINARY(16) NOT NULL,
                `sent_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`tour_date_id`, `subscriber_id`),
                CONSTRAINT `fk.nova_vk_tour_notification.tour_date_id` FOREIGN KEY (`tour_date_id`)
                    REFERENCES `nova_vk_tour_date` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.nova_vk_tour_notification.subscriber_id` FOREIGN KEY (`subscriber_id`)
                    REFERENCES `nova_vk_tour_subscriber` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `nova_vk_postcode` (
                `country` VARCHAR(2) NOT NULL,
                `postcode` VARCHAR(20) NOT NULL,
                `place` VARCHAR(180) NOT NULL DEFAULT '',
                `state` VARCHAR(100) NULL,
                `latitude` DOUBLE NOT NULL,
                `longitude` DOUBLE NOT NULL,
                PRIMARY KEY (`country`, `postcode`, `place`),
                KEY `idx.nova_vk_postcode.place` (`country`, `place`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
