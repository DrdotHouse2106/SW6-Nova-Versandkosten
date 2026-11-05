<?php declare(strict_types=1);

namespace Nova\Versandkosten\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * 1.4.1: Die bei der Installation gesetzte Beschreibung „Preis berechnet Nova Versandkosten.“ der
 * Plugin-Versandarten erschien in Kasse und Bestellbestätigung. Nur diesen unveränderten Standardtext
 * entfernen – eigene Beschreibungen bleiben. Idempotent.
 */
class Migration1794100000NovaVkShippingDescription extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794100000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE shipping_method_translation t
             INNER JOIN shipping_method m ON m.id = t.shipping_method_id
             SET t.description = NULL
             WHERE m.technical_name LIKE :prefix
               AND t.description IN (:defaults)',
            [
                'prefix' => 'nova\_versandkosten\_%',
                'defaults' => ['Preis berechnet Nova Versandkosten.', 'Price calculated by Nova Versandkosten.'],
            ],
            ['defaults' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
