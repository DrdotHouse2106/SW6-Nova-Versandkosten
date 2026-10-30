<?php declare(strict_types=1);

namespace Nova\Versandkosten\Energy;

/**
 * Gespeicherter Abruf-Zustand der Energiezuschläge (system_config „NovaVersandkosten.energyState“):
 * {series: {dhl: [EnergyEntry…], dpd: […], dpd_efs: […]}, sources: {…: {url, fetchedAt, attemptedAt, error}}}
 * – reine Auswertung ohne Shopware.
 */
final class EnergyState
{
    /**
     * Gültiger Gesamtwert eines Dienstleisters an einem Tag (DPD: Straße + Emergency Fuel Surcharge).
     *
     * @param array<string, mixed> $state
     */
    public static function valueAt(array $state, string $carrier, \DateTimeImmutable $date, bool $withEmergency = true): ?float
    {
        $entry = EnergyEntry::valueAt(self::entries($state, $carrier), $date);
        if ($entry === null) {
            return null;
        }
        $value = $entry->percent;
        if ($carrier === 'dpd' && $withEmergency) {
            $emergency = EnergyEntry::valueAt(self::entries($state, 'dpd_efs'), $date);
            $value += $emergency === null ? 0.0 : $emergency->percent;
        }

        return round($value, 2);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return list<EnergyEntry>
     */
    public static function entries(array $state, string $series): array
    {
        $out = [];
        $series = $state['series'][$series] ?? [];
        foreach (\is_array($series) ? $series : [] as $row) {
            $entry = \is_array($row) ? EnergyEntry::fromArray($row) : null;
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, float>
     */
    public static function parseLines(array $lines): array
    {
        $energy = [];
        foreach ($lines as $line) {
            if (preg_match('/^([a-z0-9_\-]+)\s*[=:]\s*([\d.,]+)\s*%?$/i', $line, $m)) {
                $energy[strtolower($m[1])] = (float) str_replace(',', '.', $m[2]);
            }
        }

        return $energy;
    }
}
