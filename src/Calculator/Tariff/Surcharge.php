<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tariff;

/**
 * Zuschlag auf den Einkaufspreis je Paket.
 *
 * Arten:
 * - fixed:      fester Betrag (Maut, Sicherheitsgebühr, Zollabfertigung, Insel …)
 * - percent:    Prozent vom Grundpreis (z. B. Währungszuschlag)
 * - energy:     Prozent vom Grundpreis, Satz kommt aus der Kanal-Einstellung je Dienstleister
 * - peak:       fester Betrag in den Monaten „months“ (Standard November/Dezember)
 * - peakInPeak: fester Betrag im Zeitraum, der in der Kanal-Einstellung eingetragen ist
 *
 * Optional eingeschränkt auf Länder und/oder PLZ (einzeln oder Bereich „25846-25847“).
 * passOn = Zuschlag wird auch auf Festpreise brutto aufgeschlagen (sonst nur Marge).
 */
final class Surcharge
{
    public const TYPES = ['fixed', 'percent', 'energy', 'peak', 'peakInPeak'];

    /**
     * @param list<string> $countries leer = alle Länder des Produkts
     * @param list<string> $postcodes leer = alle PLZ
     * @param list<int> $months
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly int $amount = 0,
        public readonly float $percent = 0.0,
        public readonly array $countries = [],
        public readonly array $postcodes = [],
        public readonly array $months = [11, 12],
        public readonly bool $passOn = false,
    ) {
    }

    public function appliesTo(string $country, string $postcode): bool
    {
        if ($this->countries !== [] && !\in_array($country, $this->countries, true)) {
            return false;
        }
        if ($this->postcodes === []) {
            return true;
        }

        $postcode = trim($postcode);
        foreach ($this->postcodes as $entry) {
            if (str_contains($entry, '-')) {
                [$from, $to] = array_map('trim', explode('-', $entry, 2));
                if (\strlen($postcode) === \strlen($from) && strcmp($postcode, $from) >= 0 && strcmp($postcode, $to) <= 0) {
                    return true;
                }
            } elseif ($entry === $postcode) {
                return true;
            }
        }

        return false;
    }
}
