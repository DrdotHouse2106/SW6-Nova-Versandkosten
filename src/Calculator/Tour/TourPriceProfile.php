<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Preisprofil einer Tour – der Preis hängt davon ab, wer fährt (z. B. „Inhaber“ oder „Fahrer“).
 *
 * Ein Profil ersetzt für die Tourtermine, denen es zugeordnet ist, den Preis und die Höchstabweichung
 * für Kleinteile/Paketware/Sperrgut sowie die Speditionsstufen. Umwegfaktor und Lieferländer bleiben die
 * des Verkaufskanals. Termine ohne Profil nutzen das Standardprofil (Einstellungen „Eigene Touren“).
 *
 * Leere Speditionsstufen bedeuten: mit diesem Profil keine Speditionsware. Der Name ist nur intern und
 * wird dem Kunden nie angezeigt.
 */
final class TourPriceProfile
{
    /**
     * @param list<array{0: float, 1: int}> $freightTiers [bis km, Preis in Cent], aufsteigend
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $smallPriceCents,
        public readonly float $smallMaxKm,
        public readonly array $freightTiers,
    ) {
    }

    /**
     * Stufen aus gespeichertem JSON ([[km, Cent], …]) – ungültige Einträge werden übersprungen.
     *
     * @return list<array{0: float, 1: int}>
     */
    public static function tiersFromArray(mixed $value): array
    {
        $tiers = [];
        foreach (\is_array($value) ? $value : [] as $tier) {
            if (!\is_array($tier)) {
                continue;
            }
            $km = $tier[0] ?? $tier['km'] ?? null;
            $price = $tier[1] ?? $tier['priceCents'] ?? null;
            if (!is_numeric($km) || !is_numeric($price) || (float) $km <= 0 || (int) $price < 0) {
                continue;
            }
            $tiers[] = [(float) $km, (int) $price];
        }
        usort($tiers, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $tiers;
    }

    /**
     * Stufen als Text für die Administration: „30=190“ je Zeile.
     *
     * @param list<array{0: float, 1: int}> $tiers
     */
    public static function tiersToText(array $tiers): string
    {
        $lines = [];
        foreach ($tiers as [$km, $price]) {
            $lines[] = self::number($km) . '=' . self::number($price / 100);
        }

        return implode("\n", $lines);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
