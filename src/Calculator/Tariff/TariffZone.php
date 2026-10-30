<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tariff;

use Nova\Versandkosten\Calculator\Money;

/**
 * Preise eines Produkts für eine Ländergruppe: entweder Gewichtsstufen oder Stückpreis + Preis je kg
 * (Gewicht auf roundKg aufgerundet, z. B. 0,1 kg = je angefangene 100 g).
 */
final class TariffZone
{
    /**
     * @param list<string> $countries
     * @param list<array{maxKg: float, price: int}> $steps Preise in Einheiten (1/10.000 €), aufsteigend
     */
    public function __construct(
        public readonly string $name,
        public readonly array $countries,
        public readonly array $steps = [],
        public readonly ?int $perPiece = null,
        public readonly ?int $perKg = null,
        public readonly float $roundKg = 0.1,
    ) {
    }

    public function covers(string $country): bool
    {
        return \in_array($country, $this->countries, true);
    }

    /** Grundpreis netto in Einheiten, null = Gewicht außerhalb der Staffel. */
    public function basePrice(float $weightKg): ?int
    {
        if ($this->steps !== []) {
            foreach ($this->steps as $step) {
                if ($weightKg <= $step['maxKg'] + 1e-9) {
                    return $step['price'];
                }
            }

            return null;
        }

        if ($this->perPiece === null) {
            return null;
        }

        // Gewicht in Gramm, auf die Rundungsstufe aufgerundet (ganzzahlig, ohne Gleitkomma-Fallen)
        $grams = (int) round($weightKg * 1000);
        $stepGrams = max(1, (int) round($this->roundKg * 1000));
        $billedGrams = intdiv($grams + $stepGrams - 1, $stepGrams) * $stepGrams;

        return $this->perPiece + Money::divRound(($this->perKg ?? 0) * $billedGrams, 1000);
    }

    public function maxStepKg(): ?float
    {
        if ($this->steps === []) {
            return null;
        }

        return $this->steps[\count($this->steps) - 1]['maxKg'];
    }
}
