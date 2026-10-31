<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Entscheidet, ob ein Tourtermin für eine Lieferadresse passt, mit welcher Variante und zu welchem Preis.
 *
 * Regeln (vom Inhaber festgelegt):
 * - Termin geschlossen oder Bestellschluss vorbei → nein.
 * - Speditionsware nur bei Terminen „mit Hänger“.
 * - Offener Termin: alle Varianten zählen, es gilt die mit der geringsten Abweichung (diese wird bei
 *   der ersten Buchung festgelegt). Festgelegter Termin: nur die festgelegte Variante.
 * - Abweichung = Luftlinie zur Variante × Umwegfaktor; Preis nach Einordnung und Abweichung – aus dem
 *   Preisprofil des Termins (wer fährt), ohne Profil aus dem Standardprofil.
 */
final class TourPlanner
{
    public static function evaluate(TourDate $date, GeoPoint $point, ?string $tier, TourPricing $pricing, \DateTimeImmutable $today): TourMatch
    {
        if ($date->status === TourDate::STATUS_CLOSED) {
            return new TourMatch($date->id, false, TourMatch::REASON_CLOSED);
        }
        if ($date->isPastDeadline($today)) {
            return new TourMatch($date->id, false, TourMatch::REASON_DEADLINE);
        }
        if ($tier === null) {
            return new TourMatch($date->id, false, TourMatch::REASON_TIER);
        }
        if ($tier === TourTier::FREIGHT && !$date->trailer) {
            return new TourMatch($date->id, false, TourMatch::REASON_TRAILER, tier: $tier);
        }

        $nearest = self::nearestVariant($date->bookableVariants(), $point, $pricing);
        if ($nearest === null) {
            return new TourMatch($date->id, false, TourMatch::REASON_NO_ROUTE, tier: $tier);
        }
        [$variant, $deviation, $along] = $nearest;
        $price = $date->pricing($pricing)->priceCents($tier, $deviation, $date->trailer);
        if ($price === null) {
            return new TourMatch($date->id, false, TourMatch::REASON_TOO_FAR, $variant->id, $variant->name, $deviation, $along, null, $tier);
        }

        return new TourMatch($date->id, true, '', $variant->id, $variant->name, $deviation, $along, $price, $tier);
    }

    /**
     * Variante mit der geringsten Abweichung (inkl. Umwegfaktor).
     *
     * @param list<TourVariant> $variants
     *
     * @return array{0: TourVariant, 1: float, 2: float}|null Variante, Abweichung km, Position entlang km
     */
    public static function nearestVariant(array $variants, GeoPoint $point, TourPricing $pricing): ?array
    {
        $best = null;
        foreach ($variants as $variant) {
            $result = Geo::toPolyline($point, $variant->points);
            if ($result === null) {
                continue;
            }
            $deviation = $pricing->deviation($result['distance']);
            if ($best === null || $deviation < $best[1]) {
                $best = [$variant, $deviation, $result['along']];
            }
        }

        return $best;
    }

    /**
     * Alle buchbaren Termine, nach Datum sortiert.
     *
     * @param list<TourDate> $dates
     *
     * @return list<TourMatch>
     */
    public static function available(array $dates, GeoPoint $point, ?string $tier, TourPricing $pricing, \DateTimeImmutable $today): array
    {
        usort($dates, static fn (TourDate $a, TourDate $b) => $a->date <=> $b->date);
        $out = [];
        foreach ($dates as $date) {
            $match = self::evaluate($date, $point, $tier, $pricing, $today);
            if ($match->available) {
                $out[] = $match;
            }
        }

        return $out;
    }

    /**
     * Liegt der Punkt im Korridor des Termins (für Benachrichtigungen)? Offener Termin: irgendeine
     * Variante; festgelegter: nur diese. Reichweite (aus dem Preisprofil des Termins): mit Hänger bis zur
     * größten Speditionsstufe, sonst bis zur Grenze für Kleinteile.
     */
    public static function inCorridor(TourDate $date, GeoPoint $point, TourPricing $pricing): ?float
    {
        $nearest = self::nearestVariant($date->bookableVariants(), $point, $pricing);
        if ($nearest === null || $nearest[1] > $date->pricing($pricing)->maxReachKm($date->trailer) + 1e-9) {
            return null;
        }

        return $nearest[1];
    }
}
