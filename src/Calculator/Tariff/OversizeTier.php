<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tariff;

use Nova\Versandkosten\Calculator\Package;

/**
 * Sperrgut-/Übermaß-Stufe (z. B. DPD „Übermaß (NC)“, DPD „Übergröße“, DHL „Sperrgut“).
 *
 * Die Stufe greift, wenn EINE der Bedingungen zutrifft. Greifen mehrere Stufen, gilt die letzte
 * in der Liste (sie ersetzt die vorherigen). Danach muss das Paket in die Höchstmaße der Stufe passen.
 *
 * Bedingungen: lengthOver, lengthAtLeast, sideOver (Breite oder Höhe), volumeOverL, girthOver,
 * exceedsDimsCm [L, B, H], weightOverKg, bulky (Paket enthält Sperrgut).
 * Höchstmaße: lengthCm, girthCm, dimsCm [L, B, H], kg.
 */
final class OversizeTier
{
    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $max
     */
    public function __construct(
        public readonly string $name,
        public readonly int $amount,
        public readonly array $condition,
        public readonly array $max = [],
    ) {
    }

    public function triggers(Package $package): bool
    {
        $c = $this->condition;
        if (isset($c['lengthOver']) && $package->length() > (float) $c['lengthOver'] + 1e-9) {
            return true;
        }
        if (isset($c['lengthAtLeast']) && $package->length() >= (float) $c['lengthAtLeast'] - 1e-9) {
            return true;
        }
        if (isset($c['sideOver']) && ($package->dims[1] > (float) $c['sideOver'] + 1e-9 || $package->dims[2] > (float) $c['sideOver'] + 1e-9)) {
            return true;
        }
        if (isset($c['volumeOverL']) && $package->volumeLiters() > (float) $c['volumeOverL'] + 1e-9) {
            return true;
        }
        if (isset($c['girthOver']) && $package->girth() > (float) $c['girthOver'] + 1e-9) {
            return true;
        }
        if (isset($c['weightOverKg']) && $package->weightKg > (float) $c['weightOverKg'] + 1e-9) {
            return true;
        }
        if (isset($c['exceedsDimsCm']) && \is_array($c['exceedsDimsCm']) && \count($c['exceedsDimsCm']) === 3) {
            $d = array_values(array_map('floatval', $c['exceedsDimsCm']));
            if (!$package->fitsInto($d[0], $d[1], $d[2])) {
                return true;
            }
        }

        return !empty($c['bulky']) && $package->bulky;
    }

    public function allows(Package $package): bool
    {
        $m = $this->max;
        if (isset($m['lengthCm']) && $package->length() > (float) $m['lengthCm'] + 1e-9) {
            return false;
        }
        if (isset($m['girthCm']) && $package->girth() > (float) $m['girthCm'] + 1e-9) {
            return false;
        }
        if (isset($m['kg']) && $package->weightKg > (float) $m['kg'] + 1e-9) {
            return false;
        }
        if (isset($m['dimsCm']) && \is_array($m['dimsCm']) && \count($m['dimsCm']) === 3) {
            $d = array_values(array_map('floatval', $m['dimsCm']));
            if (!$package->fitsInto($d[0], $d[1], $d[2])) {
                return false;
            }
        }

        return true;
    }
}
