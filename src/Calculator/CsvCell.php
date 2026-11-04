<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * CSV-Zellen für Excel/LibreOffice entschärfen (Formel-Injection, reine Logik, testbar).
 */
final class CsvCell
{
    /** Zeichen, mit denen eine Tabellenkalkulation eine Formel bzw. einen Befehl beginnt */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * Beginnt der Wert (auch nach Leerzeichen) mit = + - @, einem Tabulator oder Zeilenumbruch oder einem
     * vollbreiten Gleichheitszeichen, bekommt er ein vorangestelltes Hochkomma.
     */
    public static function safe(string $value): string
    {
        $trimmed = ltrim($value, ' ');
        if (str_starts_with($trimmed, "\u{00A0}")) {
            $trimmed = ltrim(substr($trimmed, 2), ' ');
        }
        if ($trimmed === '') {
            return $value;
        }

        return \in_array($trimmed[0], self::TRIGGERS, true) || str_starts_with($trimmed, "\u{FF1D}") ? "'" . $value : $value;
    }
}
