<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Liefert den Speditionspreis. Der Kern fragt nur, wenn die Sendung wirklich Spedition braucht.
 */
interface FreightQuoteProvider
{
    /**
     * @param list<Unit> $units alle Stücke der Sendung
     */
    public function quote(Destination $destination, array $units, float $goodsValueGross): FreightQuote;
}
