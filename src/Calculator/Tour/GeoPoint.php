<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Geokoordinate (WGS84, Grad).
 */
final class GeoPoint
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lon,
    ) {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new \InvalidArgumentException(\sprintf('Ungültige Koordinate %F, %F', $lat, $lon));
        }
    }

    /**
     * Aus einem Array {lat, lon} (z. B. Wegpunkt aus der Datenbank); null, wenn es nicht passt.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || !is_numeric($data['lat'] ?? null) || !is_numeric($data['lon'] ?? null)) {
            return null;
        }
        $lat = (float) $data['lat'];
        $lon = (float) $data['lon'];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }

        return new self($lat, $lon);
    }
}
