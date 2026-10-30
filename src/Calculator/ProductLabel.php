<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Versandlabel je Artikel für Feeds (z. B. Google Merchant Center „shipping_label“):
 * paket, sperrgut, spedition, abholung oder anfrage – die günstigste mögliche Variante für den Artikel allein.
 */
final class ProductLabel
{
    /**
     * @param array{0: float, 1: float, 2: float}|null $dimsCm
     * @param array{0: float, 1: float, 2: float} $parcelMax Grenze, ab der ein Paket Sperrgut/Übermaß ist
     */
    public static function label(string $category, ?array $dimsCm, array $parcelMax = [120.0, 60.0, 60.0]): string
    {
        return match ($category) {
            Item::FREIGHT => 'spedition',
            Item::PICKUP_ONLY => 'abholung',
            Item::ON_REQUEST => 'anfrage',
            Item::BULKY => 'sperrgut',
            default => $dimsCm !== null && !(new Package(0, $dimsCm[0], $dimsCm[1], $dimsCm[2]))->fitsInto($parcelMax[0], $parcelMax[1], $parcelMax[2])
                ? 'sperrgut' : 'paket',
        };
    }
}
