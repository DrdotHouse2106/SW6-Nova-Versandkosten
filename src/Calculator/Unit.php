<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Ein einzelnes Stück (Position × Menge aufgelöst) mit ergänzten Maßen/Gewicht.
 */
final class Unit
{
    /**
     * @param array{0: float, 1: float, 2: float} $dims absteigend sortiert, cm
     */
    public function __construct(
        public readonly Item $item,
        public readonly float $weightKg,
        public readonly array $dims,
    ) {
    }

    public function volume(): float
    {
        return $this->dims[0] * $this->dims[1] * $this->dims[2];
    }
}
