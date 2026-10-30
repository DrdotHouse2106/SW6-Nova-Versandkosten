<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Eine Warenkorbposition, wie der Rechenkern sie braucht (unabhängig von Shopware/ERPNext).
 *
 * Maße in cm, Gewicht in kg, Preis brutto je Stück in €. Fehlende Maße/Gewicht = null; der Kern
 * setzt dann die Standardwerte der Einordnung ein und meldet „Maße fehlen“.
 */
final class Item
{
    public const PARCEL = 'paket';
    public const BULKY = 'sperrgut';
    public const FREIGHT = 'spedition';
    public const PICKUP_ONLY = 'abholung';

    public const CATEGORIES = [self::PARCEL, self::BULKY, self::FREIGHT, self::PICKUP_ONLY];

    /** Sperrgut: jedes Stück ein eigenes Paket */
    public const PACK_SINGLE = 'einzelpaket';
    /** Sperrgut: mehrere Stücke gleicher Art teilen sich ein Paket */
    public const PACK_COMBINABLE = 'zusammen';

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?float $weightKg,
        public readonly ?float $lengthCm,
        public readonly ?float $widthCm,
        public readonly ?float $heightCm,
        public readonly string $category = self::PARCEL,
        public readonly string $packing = self::PACK_SINGLE,
        public readonly int $quantity = 1,
        public readonly float $unitPriceGross = 0.0,
        /** Artikel fällt unter die Sonderregel „versandkostenfrei“ des Kanals */
        public readonly bool $free = false,
        /** Frühester Versandtag (z. B. Erscheinungsdatum), null = sofort */
        public readonly ?\DateTimeImmutable $availableFrom = null,
    ) {
        if (!\in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unbekannte Versandeinordnung „%s“', $category));
        }
        if (!\in_array($packing, [self::PACK_SINGLE, self::PACK_COMBINABLE], true)) {
            throw new \InvalidArgumentException(\sprintf('Unbekannte Sperrgut-Packart „%s“', $packing));
        }
    }

    public function hasDimensions(): bool
    {
        return $this->lengthCm !== null && $this->widthCm !== null && $this->heightCm !== null
            && $this->lengthCm > 0 && $this->widthCm > 0 && $this->heightCm > 0;
    }

    public function hasWeight(): bool
    {
        return $this->weightKg !== null && $this->weightKg > 0;
    }
}
