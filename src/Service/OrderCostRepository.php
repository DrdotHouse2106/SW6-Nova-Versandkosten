<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Einkauf je Bestellung (geschätzter Einkauf netto, Einkauf je Paket, Tarifzeilen, Tarifprodukt,
 * Cargoboard-Angebots-ID) in der eigenen Tabelle nova_vk_order_cost.
 *
 * Bis 1.3.1 standen diese Werte in order.customFields – die liefert die Store-API dem Kunden mit
 * (GET /store-api/order). Die Tabelle ist keine DAL-Entität und damit weder über Store-API noch über
 * die generische Admin-API erreichbar; die Administration liest sie über
 * GET /api/_action/nova-versandkosten/order/{id} (Recht order:read).
 */
class OrderCostRepository
{
    public const TABLE = 'nova_vk_order_cost';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $orderId, float $purchaseNet, array $data): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->executeStatement(
            'INSERT INTO `' . self::TABLE . '` (order_id, order_version_id, purchase_net, data, created_at)
             VALUES (:id, :live, :purchase, :data, :now)
             ON DUPLICATE KEY UPDATE purchase_net = VALUES(purchase_net), data = VALUES(data), updated_at = :now',
            [
                'id' => Uuid::fromHexToBytes($orderId),
                'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'purchase' => round($purchaseNet, 2),
                'data' => json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE),
                'now' => $now,
            ]
        );
    }

    /**
     * @return array{purchaseNet: float, data: array<string, mixed>}|null
     */
    public function load(string $orderId): ?array
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT purchase_net, data FROM `' . self::TABLE . '` WHERE order_id = :id AND order_version_id = :live',
            ['id' => Uuid::fromHexToBytes($orderId), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if ($row === false) {
            return null;
        }
        $data = json_decode((string) ($row['data'] ?? ''), true);

        return ['purchaseNet' => (float) $row['purchase_net'], 'data' => \is_array($data) ? $data : []];
    }

    /**
     * Für die Admin-Karte: Einkauf gesamt und je Paket (Reihenfolge wie nova_versandkosten_packages).
     *
     * @return array{purchaseNet: float, packages: list<float|null>, freightQuotationId: string|null}|null
     */
    public function summary(string $orderId): ?array
    {
        $row = $this->load($orderId);
        if ($row === null) {
            return null;
        }
        $packages = [];
        foreach ((array) ($row['data']['packages'] ?? []) as $package) {
            $packages[] = \is_array($package) && is_numeric($package['purchaseNet'] ?? null) ? (float) $package['purchaseNet'] : null;
        }
        $quote = $row['data']['freightQuotationId'] ?? null;

        return [
            'purchaseNet' => $row['purchaseNet'],
            'packages' => $packages,
            'freightQuotationId' => \is_string($quote) && $quote !== '' ? $quote : null,
        ];
    }
}
