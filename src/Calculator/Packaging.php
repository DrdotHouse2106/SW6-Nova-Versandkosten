<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Ergebnis der Paketbildung.
 */
final class Packaging
{
    /**
     * @param list<Package> $packages leer bei Spedition/Abholung
     * @param list<Unit> $units alle Stücke (für die Speditionsanfrage)
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $packages,
        public readonly array $units,
        public readonly bool $dimsMissing,
    ) {
    }

    public function totalWeightKg(): float
    {
        $sum = 0.0;
        foreach ($this->units as $unit) {
            $sum += $unit->weightKg;
        }

        return $sum;
    }
}
