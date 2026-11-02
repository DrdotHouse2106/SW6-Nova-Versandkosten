<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Verkaufsregeln eines Verkaufskanals (Rechenregel Abschnitt 4–6). Alle Preise brutto in €.
 *
 * Optionen (Schlüssel in fixedPrices usw.): warenpost, kleinpaket, dpd, dhl (+ spedition, abholung).
 */
final class ChannelRules
{
    /**
     * @param array<string, float|null> $fixedPrices Festpreis je Paket im Heimatland; null = Einkauf brutto
     * @param array<string, float> $goodsValueLimits höchster Warenwert (brutto) je Option, z. B. warenpost => 30
     * @param array<string, float> $energyPercent Energiezuschlag in % je Dienstleister (dhl, dpd, …)
     * @param list<string> $allowedCountries leer = alle Länder mit Tarif
     * @param list<string> $freeCountries Länder, in denen die Versandkostenfrei-Sonderregel gilt
     * @param array<string, array{0: float, 1: float, 2: float}> $defaultDims Standardmaße je Einordnung (cm)
     * @param list<array{option: string, countries: string, min: int, max: int}> $transitTable Laufzeiten
     * @param list<string> $requestTriggers wann „Versandkosten auf Anfrage“ angeboten wird (REQUEST_*)
     * @param string $smallNoDims Kleinsendung ohne Maße: SMALL_OFF, SMALL_KLEINPAKET oder SMALL_WARENPOST
     * @param float $smallNoDimsMaxKg bis zu welchem Gesamtgewicht die Kleinsendung ohne Maße gilt
     * @param array<string, float|null> $fixedPriceMaxKg Festpreis gilt je Paket bis X kg (null = immer)
     * @param string $fixedOverMode über der Gewichtsgrenze: FIXED_OVER_PURCHASE oder FIXED_OVER_SURCHARGE
     * @param float $fixedOverSurcharge Aufschlag brutto je Paket bei FIXED_OVER_SURCHARGE
     */
    public function __construct(
        public readonly string $homeCountry = 'DE',
        public readonly float $vatPercent = 19.0,
        public readonly array $fixedPrices = ['warenpost' => 5.00, 'kleinpaket' => 7.50, 'dpd' => 7.50, 'dhl' => 10.50],
        public readonly float $foreignSurcharge = 5.00,
        public readonly bool $passOversize = true,
        public readonly float $maxPackageKg = 25.0,
        public readonly array $goodsValueLimits = ['warenpost' => 30.0, 'kleinpaket' => 50.0],
        public readonly bool $passPeak = true,
        public readonly ?\DateTimeImmutable $peakInPeakFrom = null,
        public readonly ?\DateTimeImmutable $peakInPeakUntil = null,
        public readonly array $energyPercent = [],
        public readonly bool $offerDpdOutsideEu = true,
        public readonly array $allowedCountries = [],
        public readonly array $freeCountries = ['DE'],
        public readonly float $freightSurcharge = 20.00,
        public readonly ?float $freightFallbackPrice = null,
        public readonly bool $pickupEnabled = true,
        public readonly array $defaultDims = [
            Item::PARCEL => [30.0, 20.0, 15.0],
            Item::BULKY => [130.0, 40.0, 40.0],
            Item::FREIGHT => [120.0, 80.0, 100.0],
            Item::PICKUP_ONLY => [30.0, 20.0, 15.0],
            Item::ON_REQUEST => [30.0, 20.0, 15.0],
        ],
        public readonly float $defaultWeightKg = 1.0,
        public readonly array $transitTable = [],
        public readonly array $requestTriggers = [],
        public readonly string $smallNoDims = self::SMALL_OFF,
        public readonly float $smallNoDimsMaxKg = 1.0,
        public readonly array $fixedPriceMaxKg = [],
        public readonly string $fixedOverMode = self::FIXED_OVER_PURCHASE,
        public readonly float $fixedOverSurcharge = 0.0,
    ) {
    }

    /** Kleinsendung ohne Maße: Standardmaße wie bisher (passt nie in Warenpost/Kleinpaket) */
    public const SMALL_OFF = 'off';
    /** Kleinsendung ohne Maße gilt als Kleinpaket-fähig (nicht Warenpost) */
    public const SMALL_KLEINPAKET = 'kleinpaket';
    /** Kleinsendung ohne Maße gilt als Warenpost-fähig (und damit auch Kleinpaket-fähig) */
    public const SMALL_WARENPOST = 'warenpost';

    public const SMALL_MODES = [self::SMALL_OFF, self::SMALL_KLEINPAKET, self::SMALL_WARENPOST];

    /** Über der Gewichtsgrenze des Festpreises: Einkauf brutto */
    public const FIXED_OVER_PURCHASE = 'purchase';
    /** Über der Gewichtsgrenze des Festpreises: Einkauf brutto + Aufschlag */
    public const FIXED_OVER_SURCHARGE = 'purchaseSurcharge';

    /**
     * Gilt eine Kleinsendung ohne Maße als passend für diese Option? Warenpost-fähig schließt
     * Kleinpaket ein; DPD/DHL rechnen weiter mit den Standardmaßen.
     */
    public function smallNoDimsFits(string $option): bool
    {
        return match ($this->smallNoDims) {
            self::SMALL_WARENPOST => \in_array($option, ['warenpost', 'kleinpaket'], true),
            self::SMALL_KLEINPAKET => $option === 'kleinpaket',
            default => false,
        };
    }

    /** Gewichtsgrenze des Festpreises je Paket, null = Festpreis gilt immer. */
    public function fixedPriceMaxKg(string $option): ?float
    {
        $max = $this->fixedPriceMaxKg[$option] ?? null;

        return $max === null || $max <= 0 ? null : (float) $max;
    }

    /** Spedition nötig, aber ohne Preis (kein Cargoboard-Schlüssel, Fehler ohne Ersatzpreis) */
    public const REQUEST_FREIGHT = 'freight';
    /** Für das Land gibt es keinen Tarif */
    public const REQUEST_NO_TARIFF = 'noTariff';
    /** Warenkorb enthält einen Artikel „nur auf Anfrage“ */
    public const REQUEST_ITEMS = 'items';
    /** zusätzlich zur Spedition (Kunde kann alternativ anfragen, z. B. eigene Tour) */
    public const REQUEST_WITH_FREIGHT = 'withFreight';
    /** immer anbieten */
    public const REQUEST_ALWAYS = 'always';

    public const REQUEST_TRIGGERS = [self::REQUEST_FREIGHT, self::REQUEST_NO_TARIFF, self::REQUEST_ITEMS, self::REQUEST_WITH_FREIGHT, self::REQUEST_ALWAYS];

    public function requestWhen(string $trigger): bool
    {
        return \in_array($trigger, $this->requestTriggers, true);
    }

    public function fixedPrice(string $option): ?float
    {
        $price = $this->fixedPrices[$option] ?? null;

        return $price === null ? null : (float) $price;
    }

    public function goodsValueLimit(string $option): ?float
    {
        return isset($this->goodsValueLimits[$option]) ? (float) $this->goodsValueLimits[$option] : null;
    }

    public function energyPercent(string $carrier): float
    {
        return (float) ($this->energyPercent[$carrier] ?? 0.0);
    }

    public function isCountryAllowed(string $country): bool
    {
        return $this->allowedCountries === [] || \in_array($country, $this->allowedCountries, true);
    }

    /** @return array{0: float, 1: float, 2: float} */
    public function defaultDimsFor(string $category): array
    {
        return $this->defaultDims[$category] ?? $this->defaultDims[Item::PARCEL] ?? [30.0, 20.0, 15.0];
    }

    public function isPeakInPeak(\DateTimeImmutable $date): bool
    {
        if ($this->peakInPeakFrom === null || $this->peakInPeakUntil === null) {
            return false;
        }
        $day = $date->format('Y-m-d');

        return $day >= $this->peakInPeakFrom->format('Y-m-d') && $day <= $this->peakInPeakUntil->format('Y-m-d');
    }

    /**
     * Laufzeit-Tabelle aus Text: je Zeile „option;länder;min-max“, erste passende Zeile gewinnt.
     * Option „*“ = alle, Länder: ISO-Codes mit Komma, „EU“, „NACHBARN“ (EU-Nachbarn Deutschlands) oder „*“.
     *
     * @return list<array{option: string, countries: string, min: int, max: int}>
     */
    public static function parseTransitTable(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(';', $line));
            if (\count($parts) !== 3 || !preg_match('/^(\d+)\s*(?:-\s*(\d+))?$/', $parts[2], $m)) {
                continue;
            }
            $min = (int) $m[1];
            $max = isset($m[2]) ? (int) $m[2] : $min;
            $rows[] = [
                'option' => strtolower($parts[0]),
                'countries' => strtoupper(str_replace(' ', '', $parts[1])),
                'min' => min($min, $max),
                'max' => max($min, $max),
            ];
        }

        return $rows;
    }

    public const DEFAULT_TRANSIT_TABLE = "warenpost;DE;1-3\nwarenpost;*;4-10\n*;DE;1-2\n*;NACHBARN;2-3\n*;EU;3-5\n*;CH,GB,NO,LI;3-6\n*;*;4-8";

    /** @return array{0: int, 1: int}|null */
    public function transitDays(string $option, string $country): ?array
    {
        $table = $this->transitTable !== [] ? $this->transitTable : self::parseTransitTable(self::DEFAULT_TRANSIT_TABLE);
        foreach ($table as $row) {
            if ($row['option'] !== '*' && $row['option'] !== $option) {
                continue;
            }
            if (!Countries::matches($row['countries'], $country)) {
                continue;
            }

            return [$row['min'], $row['max']];
        }

        return null;
    }
}
