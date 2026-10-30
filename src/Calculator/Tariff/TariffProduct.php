<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tariff;

use Nova\Versandkosten\Calculator\Package;

/**
 * Ein Versandprodukt eines Dienstleisters (z. B. „DHL Paket national“), Einkaufspreise netto.
 *
 * „option“ ordnet es einer Versandart im Shop zu: warenpost, kleinpaket, dpd oder dhl.
 */
final class TariffProduct
{
    public const OPTIONS = ['warenpost', 'kleinpaket', 'dpd', 'dhl'];

    /**
     * @param list<TariffZone> $zones
     * @param list<OversizeTier> $oversize
     * @param list<Surcharge> $surcharges
     * @param array{0: float, 1: float, 2: float}|null $maxDimsCm
     */
    public function __construct(
        public readonly string $id,
        public readonly string $carrier,
        public readonly string $option,
        public readonly string $name,
        public readonly array $zones,
        public readonly float $maxKg = 31.5,
        public readonly ?array $maxDimsCm = null,
        public readonly ?float $maxLengthCm = null,
        public readonly ?float $maxGirthCm = null,
        public readonly ?float $maxGoodsValue = null,
        public readonly bool $singlePackage = false,
        public readonly bool $allowBulky = true,
        public readonly bool $basicTracking = false,
        public readonly array $oversize = [],
        public readonly array $surcharges = [],
    ) {
    }

    public function zoneFor(string $country): ?TariffZone
    {
        foreach ($this->zones as $zone) {
            if ($zone->covers($country)) {
                return $zone;
            }
        }

        return null;
    }

    /** @return list<string> alle Länder mit Preis */
    public function countries(): array
    {
        $all = [];
        foreach ($this->zones as $zone) {
            foreach ($zone->countries as $country) {
                $all[$country] = true;
            }
        }

        return array_keys($all);
    }

    /** Letzte greifende Sperrgut-/Übermaß-Stufe oder null. */
    public function oversizeTierFor(Package $package): ?OversizeTier
    {
        $hit = null;
        foreach ($this->oversize as $tier) {
            if ($tier->triggers($package)) {
                $hit = $tier;
            }
        }

        return $hit;
    }

    /**
     * Passt das Paket? Rückgabe: null = passt, sonst Grund (deutsch, für Admin/Log).
     */
    public function rejectReason(Package $package): ?string
    {
        if ($package->weightKg > $this->maxKg + 1e-9) {
            return \sprintf('zu schwer (%.1f kg > %.1f kg)', $package->weightKg, $this->maxKg);
        }
        if ($package->bulky && !$this->allowBulky) {
            return 'Sperrgut nicht möglich';
        }

        $tier = $this->oversizeTierFor($package);
        if ($tier !== null) {
            return $tier->allows($package) ? null : \sprintf('zu groß auch für „%s“', $tier->name);
        }

        if ($this->maxDimsCm !== null && !$package->fitsInto($this->maxDimsCm[0], $this->maxDimsCm[1], $this->maxDimsCm[2])) {
            return \sprintf('Maße über %s cm', implode(' × ', array_map(static fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ','), $this->maxDimsCm)));
        }
        if ($this->maxLengthCm !== null && $package->length() > $this->maxLengthCm + 1e-9) {
            return 'zu lang';
        }
        if ($this->maxGirthCm !== null && $package->girth() > $this->maxGirthCm + 1e-9) {
            return 'Gurtmaß zu groß';
        }

        return null;
    }
}
