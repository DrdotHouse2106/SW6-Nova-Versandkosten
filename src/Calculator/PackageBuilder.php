<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Pakete bilden (Rechenregel Abschnitt 2).
 *
 * 1. Ein Artikel „Spedition“ → ganze Sendung Spedition (keine Pakete).
 * 2. Ein Artikel „nur Abholung“ → ganze Sendung nur Abholung (hat Vorrang vor Spedition).
 *    Ein Artikel „nur auf Anfrage“ → ganze Sendung auf Anfrage (Vorrang vor Spedition, nach Abholung).
 * 3. Sonst:
 *    - Sperrgut „Einzelpaket“: jedes Stück ein eigenes Paket.
 *    - Sperrgut „zusammen packbar“: Stücke desselben Artikels teilen sich Pakete bis zum Höchstgewicht.
 *    - Übrige Artikel (größte zuerst) kommen mit in das größte vorhandene Paket, solange es das
 *      Höchstgewicht (Standard 25 kg) nicht überschreitet; sonst neues Paket. Das Paketmaß wächst
 *      je Kante auf den größten enthaltenen Artikel („der größte Artikel bestimmt das Paketmaß“).
 *    - Ein Stück, das allein schwerer als das Höchstgewicht ist, bildet ein eigenes Paket.
 *
 * Kleinsendung ohne Maße (Einstellung je Kanal): Haben ALLE Artikel die Einordnung „Paket“ und KEINE
 * Maße und liegt das Gesamtgewicht bis zur eingestellten Grenze, wird die Sendung als
 * `smallNoDims` markiert. Der Rechner prüft dann für Warenpost/Kleinpaket (je nach Einstellung) nur
 * Gewicht, Warenwert und „ein Paket“, nicht die Standardmaße. Sobald ein Artikel Maße hat, wird wie
 * bisher exakt geprüft.
 *
 * Stücke sind unteilbar. „Je angefangene 25 kg ein Paket“ ergibt sich damit, solange die einzelnen
 * Stücke deutlich leichter als 25 kg sind (Normalfall). Bei mehreren schweren Stücken
 * (z. B. 3 × 13 kg) entstehen ehrlicherweise 3 Pakete statt rechnerisch 2.
 */
final class PackageBuilder
{
    public const MODE_PARCEL = 'parcel';
    public const MODE_FREIGHT = 'freight';
    public const MODE_PICKUP = 'pickup';
    public const MODE_REQUEST = 'request';

    /**
     * @param list<Item> $items
     */
    public static function build(array $items, ChannelRules $rules): Packaging
    {
        $dimsMissing = false;
        $mode = self::MODE_PARCEL;
        foreach ($items as $item) {
            if ($item->category === Item::PICKUP_ONLY) {
                $mode = self::MODE_PICKUP;
            } elseif ($item->category === Item::ON_REQUEST && $mode !== self::MODE_PICKUP) {
                $mode = self::MODE_REQUEST;
            } elseif ($item->category === Item::FREIGHT && $mode === self::MODE_PARCEL) {
                $mode = self::MODE_FREIGHT;
            }
        }

        // Stücke mit (ggf. ergänzten) Maßen und Gewicht
        $units = [];
        foreach ($items as $item) {
            if (!$item->hasDimensions() || !$item->hasWeight()) {
                $dimsMissing = true;
            }
            $dims = $item->hasDimensions()
                ? [(float) $item->lengthCm, (float) $item->widthCm, (float) $item->heightCm]
                : $rules->defaultDimsFor($item->category);
            $weight = $item->hasWeight() ? (float) $item->weightKg : $rules->defaultWeightKg;
            for ($i = 0; $i < max(0, $item->quantity); ++$i) {
                $units[] = new Unit($item, $weight, Package::sortDims($dims[0], $dims[1], $dims[2]));
            }
        }

        if ($mode !== self::MODE_PARCEL) {
            return new Packaging($mode, [], $units, $dimsMissing);
        }

        $max = $rules->maxPackageKg > 0 ? $rules->maxPackageKg : 25.0;
        $packages = [];

        // Sperrgut zuerst: Einzelpaket je Stück, zusammen packbar je Artikel
        $combinable = [];
        foreach ($units as $unit) {
            if ($unit->item->category !== Item::BULKY) {
                continue;
            }
            if ($unit->item->packing === Item::PACK_COMBINABLE) {
                $combinable[$unit->item->id][] = $unit;
                continue;
            }
            $packages[] = self::newPackage($unit, true);
        }
        foreach ($combinable as $group) {
            $current = null;
            foreach ($group as $unit) {
                if ($current !== null && $current->weightKg + $unit->weightKg <= $max + 1e-9) {
                    self::add($current, $unit);
                    continue;
                }
                $current = self::newPackage($unit, true);
                $packages[] = $current;
            }
        }

        // Normale Stücke: größte zuerst, ins größte Paket mit Platz (Gewicht)
        $normal = array_values(array_filter($units, static fn (Unit $u) => $u->item->category !== Item::BULKY));
        usort($normal, static function (Unit $a, Unit $b): int {
            return [$b->volume(), $b->weightKg, $a->item->id] <=> [$a->volume(), $a->weightKg, $b->item->id];
        });

        foreach ($normal as $unit) {
            $target = null;
            if ($unit->weightKg <= $max + 1e-9) {
                $candidates = $packages;
                usort($candidates, static fn (Package $a, Package $b) => $b->volumeLiters() <=> $a->volumeLiters());
                foreach ($candidates as $candidate) {
                    if ($candidate->weightKg + $unit->weightKg <= $max + 1e-9) {
                        $target = $candidate;
                        break;
                    }
                }
            }
            if ($target === null) {
                $packages[] = self::newPackage($unit, false);
                continue;
            }
            self::add($target, $unit);
        }

        return new Packaging(self::MODE_PARCEL, $packages, $units, $dimsMissing, self::isSmallNoDims($items, $units, $rules));
    }

    /**
     * @param list<Item> $items
     * @param list<Unit> $units
     */
    private static function isSmallNoDims(array $items, array $units, ChannelRules $rules): bool
    {
        if ($rules->smallNoDims === ChannelRules::SMALL_OFF || $items === [] || $units === []) {
            return false;
        }
        $weight = 0.0;
        foreach ($items as $item) {
            if ($item->category !== Item::PARCEL || $item->hasDimensions()) {
                return false;
            }
        }
        foreach ($units as $unit) {
            $weight += $unit->weightKg;
        }

        return $weight <= $rules->smallNoDimsMaxKg + 1e-9;
    }

    private static function newPackage(Unit $unit, bool $bulky): Package
    {
        $package = new Package($unit->weightKg, $unit->dims[0], $unit->dims[1], $unit->dims[2], $bulky);
        $package->contents[] = $unit->item->label;

        return $package;
    }

    private static function add(Package $package, Unit $unit): void
    {
        $package->weightKg += $unit->weightKg;
        $package->growTo($unit->dims[0], $unit->dims[1], $unit->dims[2]);
        $package->contents[] = $unit->item->label;
    }
}
