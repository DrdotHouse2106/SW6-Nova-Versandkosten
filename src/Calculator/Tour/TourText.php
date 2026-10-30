<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Texte rund um die Tour (Bestellkommentar, Datumsanzeige).
 */
final class TourText
{
    private const DAYS_DE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    private const DAYS_EN = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    private const DAYS_LONG_DE = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    /** „Fr, 14.11.2026“ bzw. „Fri, 14/11/2026“ */
    public static function date(\DateTimeImmutable $date, string $locale = 'de'): string
    {
        $w = (int) $date->format('w');
        if (str_starts_with(strtolower($locale), 'de')) {
            return self::DAYS_DE[$w] . ', ' . $date->format('d.m.Y');
        }

        return self::DAYS_EN[$w] . ', ' . $date->format('d/m/Y');
    }

    /** „Freitag, 14.11.2026“ (für Mails) */
    public static function longDate(\DateTimeImmutable $date): string
    {
        return self::DAYS_LONG_DE[(int) $date->format('w')] . ', ' . $date->format('d.m.Y');
    }

    /**
     * Bestellkommentar: „TOUR: Fr, 14.11.2026“ vorne, ein Kundenkommentar bleibt dahinter erhalten.
     */
    public static function comment(\DateTimeImmutable $date, ?string $customerComment): string
    {
        $prefix = 'TOUR: ' . self::date($date, 'de');
        $customerComment = trim((string) $customerComment);
        if ($customerComment === '') {
            return $prefix;
        }
        if (str_starts_with($customerComment, $prefix)) {
            return $customerComment;
        }

        return $prefix . "\n" . $customerComment;
    }

    /** „PLZ-Gebiet 35…“ – nur die Leitregion, nie eine Strecke */
    public static function area(string $postcode): string
    {
        $digits = preg_replace('/\D/', '', $postcode) ?? '';

        return \strlen($digits) >= 2 ? substr($digits, 0, 2) . '…' : $postcode;
    }
}
