<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Ländergruppen für Mehrwertsteuer (EU / Nicht-EU) und Laufzeit-Tabelle.
 */
final class Countries
{
    /** EU-Mitgliedstaaten (Stand 2026) */
    public const EU = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
        // Monaco gehört umsatzsteuerlich zu Frankreich
        'MC',
    ];

    /** EU-Nachbarländer Deutschlands (für die Laufzeit-Tabelle) */
    public const NEIGHBOURS = ['AT', 'BE', 'CZ', 'DK', 'FR', 'LU', 'NL', 'PL'];

    public static function isEu(string $country): bool
    {
        return \in_array(strtoupper($country), self::EU, true);
    }

    /**
     * @param string $spec „*“, „EU“, „NACHBARN“, „NICHT-EU“ oder ISO-Codes mit Komma (auch gemischt)
     */
    public static function matches(string $spec, string $country): bool
    {
        $country = strtoupper($country);
        foreach (explode(',', strtoupper($spec)) as $token) {
            $token = trim($token);
            $hit = match ($token) {
                '*' => true,
                'EU' => self::isEu($country),
                'NICHT-EU', 'NON-EU' => !self::isEu($country),
                'NACHBARN', 'NEIGHBOURS' => \in_array($country, self::NEIGHBOURS, true),
                default => $token === $country,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
