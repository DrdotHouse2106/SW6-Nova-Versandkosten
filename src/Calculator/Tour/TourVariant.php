<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Ein Weg einer Strecke (z. B. „über A-Stadt“) mit geordneten Wegpunkten.
 * Einmalige Fahrten mit eigenen Wegpunkten haben genau eine Variante mit der ID CUSTOM.
 */
final class TourVariant
{
    public const CUSTOM = 'custom';

    /**
     * @param list<GeoPoint> $points
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $points,
    ) {
    }

    /**
     * Wegpunkte aus gespeichertem JSON [{label, postcode, lat, lon}, …]; ungültige werden übersprungen.
     *
     * @return list<GeoPoint>
     */
    public static function pointsFromArray(mixed $waypoints): array
    {
        $points = [];
        foreach (\is_array($waypoints) ? $waypoints : [] as $waypoint) {
            $point = GeoPoint::fromArray($waypoint);
            if ($point !== null) {
                $points[] = $point;
            }
        }

        return $points;
    }
}
