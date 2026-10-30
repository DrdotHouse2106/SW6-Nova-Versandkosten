<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Geometrie für Touren: Luftlinie zwischen zwei Punkten und Abstand eines Punkts zu einer Strecke
 * (Polylinie der Wegpunkte).
 *
 * Für den Abstand Punkt–Strecke wird um den Punkt herum flach projiziert (äquidistante Projektion);
 * bei Abständen bis einige 100 km ist der Fehler gegenüber der Kugel deutlich unter 1 %.
 */
final class Geo
{
    /** Mittlerer Erdradius in km */
    public const EARTH_RADIUS_KM = 6371.0088;

    /** Luftlinie (Haversine) in km */
    public static function distanceKm(GeoPoint $a, GeoPoint $b): float
    {
        $lat1 = deg2rad($a->lat);
        $lat2 = deg2rad($b->lat);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad($b->lon - $a->lon);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
    }

    /**
     * Kürzester Abstand (Luftlinie, km) des Punkts zur Polylinie und Position des nächsten Punkts
     * entlang der Linie (km ab dem ersten Wegpunkt) – Grundlage für die Lade-Reihenfolge.
     *
     * @param list<GeoPoint> $line
     *
     * @return array{distance: float, along: float}|null null bei leerer Linie
     */
    public static function toPolyline(GeoPoint $point, array $line): ?array
    {
        if ($line === []) {
            return null;
        }
        if (\count($line) === 1) {
            return ['distance' => self::distanceKm($point, $line[0]), 'along' => 0.0];
        }

        $kmPerDegLat = self::EARTH_RADIUS_KM * M_PI / 180;
        $kmPerDegLon = $kmPerDegLat * cos(deg2rad($point->lat));
        $project = static fn (GeoPoint $p): array => [($p->lon - $point->lon) * $kmPerDegLon, ($p->lat - $point->lat) * $kmPerDegLat];

        $best = null;
        $bestAlong = 0.0;
        $walked = 0.0;
        for ($i = 0, $n = \count($line) - 1; $i < $n; ++$i) {
            [$ax, $ay] = $project($line[$i]);
            [$bx, $by] = $project($line[$i + 1]);
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            // Projektion des Punkts (Ursprung) auf die Strecke A–B, auf [0, 1] begrenzt
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $cx = $ax + $t * $dx;
            $cy = $ay + $t * $dy;
            $distance = sqrt($cx * $cx + $cy * $cy);
            $segment = self::distanceKm($line[$i], $line[$i + 1]);
            if ($best === null || $distance < $best) {
                $best = $distance;
                $bestAlong = $walked + $t * $segment;
            }
            $walked += $segment;
        }

        return ['distance' => (float) $best, 'along' => $bestAlong];
    }

    /**
     * Gesamtlänge der Polylinie (Luftlinie je Abschnitt) in km.
     *
     * @param list<GeoPoint> $line
     */
    public static function length(array $line): float
    {
        $sum = 0.0;
        for ($i = 0, $n = \count($line) - 1; $i < $n; ++$i) {
            $sum += self::distanceKm($line[$i], $line[$i + 1]);
        }

        return $sum;
    }
}
