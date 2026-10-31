<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Preise und Grenzen der eigenen Tour (je Verkaufskanal einstellbar).
 *
 * - Kleinteile, Paketware, Sperrgut: ein Preis bis zur Höchstabweichung (Standard 50 € bis 10 km).
 * - Speditionsware (nur mit Hänger): Stufen „bis km → Preis“ (Standard bis 30 km 190 €, bis 60 km 290 €).
 * - Darüber: keine Tour.
 *
 * Abweichung = Luftlinie zur Strecke × Umwegfaktor (Standard 1,3). Preise brutto in Cent.
 *
 * Diese Werte sind das Standardprofil. Ein Tourtermin kann ein eigenes Preisprofil haben
 * (TourPriceProfile, z. B. je nach Fahrer); dann gelten dessen Preise und Grenzen, siehe withProfile().
 */
final class TourPricing
{
    public const DEFAULT_SMALL_PRICE_CENTS = 5000;
    public const DEFAULT_SMALL_MAX_KM = 10.0;
    /** @var list<array{0: float, 1: int}> */
    public const DEFAULT_FREIGHT_TIERS = [[30.0, 19000], [60.0, 29000]];
    public const DEFAULT_DETOUR_FACTOR = 1.3;

    /**
     * @param list<array{0: float, 1: int}> $freightTiers [bis km, Preis in Cent], aufsteigend
     * @param list<string>                  $countries    Lieferländer (ISO), Standard DE
     */
    public function __construct(
        public readonly int $smallPriceCents = self::DEFAULT_SMALL_PRICE_CENTS,
        public readonly float $smallMaxKm = self::DEFAULT_SMALL_MAX_KM,
        public readonly array $freightTiers = self::DEFAULT_FREIGHT_TIERS,
        public readonly float $detourFactor = self::DEFAULT_DETOUR_FACTOR,
        public readonly array $countries = ['DE'],
    ) {
    }

    /**
     * Stufen aus dem Einstellungstext „30=190“ je Zeile (auch „30;190“, Komma als Dezimalzeichen).
     * Ungültige Zeilen werden übersprungen; leer = Standard (bzw. keine Stufen, wenn
     * $defaultIfEmpty false ist – so bei Preisprofilen: dann keine Speditionsware).
     *
     * @return list<array{0: float, 1: int}>
     */
    public static function parseTiers(string $text, bool $defaultIfEmpty = true): array
    {
        $tiers = [];
        foreach (preg_split('/[\r\n]+/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:km)?\s*[=;:]\s*(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)?$/i', $line, $m) !== 1) {
                continue;
            }
            $km = (float) str_replace(',', '.', $m[1]);
            $price = (int) round((float) str_replace(',', '.', $m[2]) * 100);
            if ($km > 0) {
                $tiers[] = [$km, $price];
            }
        }
        usort($tiers, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $tiers === [] && $defaultIfEmpty ? self::DEFAULT_FREIGHT_TIERS : $tiers;
    }

    /**
     * Preise eines Tourtermins: mit Preisprofil dessen Preise und Grenzen (Umwegfaktor und Lieferländer
     * bleiben), ohne Profil unverändert das Standardprofil.
     */
    public function withProfile(?TourPriceProfile $profile): self
    {
        if ($profile === null) {
            return $this;
        }

        return new self($profile->smallPriceCents, $profile->smallMaxKm, $profile->freightTiers, $this->detourFactor, $this->countries);
    }

    public function isCountryAllowed(string $iso): bool
    {
        return \in_array(strtoupper($iso), $this->countries, true);
    }

    /** Abweichung in km aus der Luftlinie zur Strecke */
    public function deviation(float $airKm): float
    {
        return $airKm * max(1.0, $this->detourFactor);
    }

    /**
     * Preis in Cent für Einordnung und Abweichung – null, wenn keine Tour möglich ist.
     */
    public function priceCents(string $tier, float $deviationKm, bool $trailer): ?int
    {
        if ($tier === TourTier::SMALL) {
            return $deviationKm <= $this->smallMaxKm + 1e-9 ? $this->smallPriceCents : null;
        }
        if ($tier !== TourTier::FREIGHT || !$trailer) {
            return null;
        }
        foreach ($this->freightTiers as [$km, $price]) {
            if ($deviationKm <= $km + 1e-9) {
                return $price;
            }
        }

        return null;
    }

    /** Größte Abweichung, bei der überhaupt noch eine Tour möglich ist (Interessenten, Hinweise) */
    public function maxReachKm(bool $trailer): float
    {
        $max = $this->smallMaxKm;
        if ($trailer) {
            foreach ($this->freightTiers as [$km]) {
                $max = max($max, $km);
            }
        }

        return $max;
    }
}
