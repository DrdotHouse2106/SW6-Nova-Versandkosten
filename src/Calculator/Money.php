<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Geldbeträge rechnet der Kern ganzzahlig, um Rundungsfehler von Gleitkommazahlen zu vermeiden.
 *
 * - „Einheiten“ = 1/10.000 € (Hundertstel-Cent). Darin stecken Einkaufspreise netto samt
 *   Prozentzuschlägen ungerundet (z. B. 19 % von 9,45 € = 1,7955 €).
 * - Gerundet wird erst nach der Mehrwertsteuer kaufmännisch auf Cent (Rechenregel Abschnitt 4/5).
 */
final class Money
{
    public const SCALE = 10000;

    public static function fromEuro(float|int|string $euro): int
    {
        if (\is_string($euro)) {
            $euro = (float) str_replace(',', '.', trim($euro));
        }

        return (int) round($euro * self::SCALE);
    }

    /** Prozentsatz eines Betrags in Einheiten, kaufmännisch auf Einheiten gerundet. */
    public static function percentOf(int $units, float $percent): int
    {
        $basisPoints = (int) round($percent * 100);

        return self::divRound($units * $basisPoints, 10000);
    }

    /** Netto-Einheiten → Cent (ohne Steuer), kaufmännisch gerundet. */
    public static function toCents(int $units): int
    {
        return self::divRound($units, 100);
    }

    /** Netto-Einheiten → Brutto-Cent, kaufmännisch gerundet. */
    public static function grossCents(int $netUnits, float $vatPercent): int
    {
        $vatBasisPoints = (int) round($vatPercent * 100);

        return self::divRound($netUnits * (10000 + $vatBasisPoints), 10000 * 100);
    }

    public static function eurosToCents(float $euro): int
    {
        return (int) round($euro * 100);
    }

    public static function centsToFloat(int $cents): float
    {
        return $cents / 100;
    }

    public static function unitsToFloat(int $units): float
    {
        return $units / self::SCALE;
    }

    /** Ganzzahlige Division, kaufmännisch gerundet (Hälfte von der Null weg). */
    public static function divRound(int $dividend, int $divisor): int
    {
        if ($divisor === 0) {
            throw new \InvalidArgumentException('Division durch 0');
        }

        $negative = ($dividend < 0) !== ($divisor < 0);
        $dividend = abs($dividend);
        $divisor = abs($divisor);
        $result = intdiv($dividend + intdiv($divisor, 2), $divisor);

        return $negative ? -$result : $result;
    }

    /** 1050 → „10,50“ */
    public static function formatCents(int $cents, string $decimal = ','): string
    {
        return number_format($cents / 100, 2, $decimal, '');
    }
}
