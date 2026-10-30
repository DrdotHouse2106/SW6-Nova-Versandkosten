<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

use Nova\Versandkosten\Calculator\Item;

/**
 * Einordnung des Warenkorbs für die Tour. Die höchste Einordnung bestimmt den Preis:
 * Speditionsware vor Kleinteilen/Paketware/Sperrgut.
 *
 * Artikel „Nur Abholung“ oder „Nur auf Anfrage“ schließen die Tour aus (Abholung bzw. individuelle
 * Anfrage bleiben die Wege für diese Artikel).
 */
final class TourTier
{
    public const SMALL = 'klein';
    public const FREIGHT = 'spedition';

    /**
     * @param list<Item> $items
     */
    public static function fromItems(array $items): ?string
    {
        if ($items === []) {
            return null;
        }
        $tier = self::SMALL;
        foreach ($items as $item) {
            if (\in_array($item->category, [Item::PICKUP_ONLY, Item::ON_REQUEST], true)) {
                return null;
            }
            if ($item->category === Item::FREIGHT) {
                $tier = self::FREIGHT;
            }
        }

        return $tier;
    }
}
