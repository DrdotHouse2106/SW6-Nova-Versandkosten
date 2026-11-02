<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

use Nova\Versandkosten\Calculator\Tariff\TariffSet;

/**
 * Führt Testfälle aus einer JSON-Datei gegen eine Tarifdatei aus (gleiche Eingabe → gleicher Preis,
 * egal ob Shopware oder ERPNext rechnet). Format: tests/Fixtures/testfaelle-beispiel.json.
 */
final class CaseRunner
{
    /**
     * @param array<string, mixed> $spec
     *
     * @return list<array{case: string, title: string, check: string, expected: string, actual: string, ok: bool}>
     */
    public static function run(TariffSet $tariffs, array $spec): array
    {
        $rows = [];
        $channels = \is_array($spec['channels'] ?? null) ? $spec['channels'] : [];
        $defaults = \is_array($spec['defaults'] ?? null) ? $spec['defaults'] : [];
        $calculator = new ShippingCalculator();

        foreach ((array) ($spec['cases'] ?? []) as $case) {
            if (!\is_array($case)) {
                continue;
            }
            $id = (string) ($case['id'] ?? '?');
            $title = (string) ($case['title'] ?? '');
            $channel = (array) ($channels[$case['channel'] ?? 'standard'] ?? []);
            $rules = self::rules($channel + $defaults);

            $items = [];
            foreach ((array) ($case['items'] ?? []) as $i => $raw) {
                $raw = (array) $raw;
                $dims = isset($raw['dims']) && \is_array($raw['dims']) ? array_values($raw['dims']) : [null, null, null];
                $items[] = new Item(
                    (string) ($raw['id'] ?? ('pos' . $i)),
                    (string) ($raw['label'] ?? ('Position ' . ($i + 1))),
                    isset($raw['weightKg']) ? (float) $raw['weightKg'] : null,
                    isset($dims[0]) ? (float) $dims[0] : null,
                    isset($dims[1]) ? (float) $dims[1] : null,
                    isset($dims[2]) ? (float) $dims[2] : null,
                    (string) ($raw['category'] ?? Item::PARCEL),
                    (string) ($raw['packing'] ?? Item::PACK_SINGLE),
                    (int) ($raw['qty'] ?? 1),
                    (float) ($raw['price'] ?? 0),
                    (bool) ($raw['free'] ?? false),
                );
            }

            $freight = new class($case['freightQuote'] ?? null) implements FreightQuoteProvider {
                public function __construct(private readonly mixed $quote)
                {
                }

                public function quote(Destination $destination, array $units, float $goodsValueGross): FreightQuote
                {
                    if (\is_array($this->quote) && isset($this->quote['net'])) {
                        return FreightQuote::ok((float) $this->quote['net'], isset($this->quote['daysMin']) ? (int) $this->quote['daysMin'] : null, isset($this->quote['daysMax']) ? (int) $this->quote['daysMax'] : null);
                    }
                    if ($this->quote === 'error') {
                        return FreightQuote::error('Testfehler');
                    }

                    return FreightQuote::notConfigured();
                }
            };

            $date = new \DateTimeImmutable((string) ($case['date'] ?? $defaults['date'] ?? '2027-03-10T10:00:00'));
            $result = $calculator->calculate(
                $items,
                new Destination((string) ($case['country'] ?? 'DE'), (string) ($case['postcode'] ?? '')),
                $rules,
                $tariffs,
                $date,
                $freight,
            );

            foreach ((array) ($case['expect'] ?? []) as $key => $expected) {
                $option = $result->option((string) $key);
                $actual = ($option !== null && $option->available) ? Money::formatCents($option->saleCents) : '–';
                $exp = $expected === false || $expected === null ? '–' : Money::formatCents(Money::eurosToCents((float) $expected));
                $rows[] = ['case' => $id, 'title' => $title, 'check' => (string) $key, 'expected' => $exp, 'actual' => $actual, 'ok' => $exp === $actual];
            }
            foreach ((array) ($case['expectPackages'] ?? []) as $key => $count) {
                $option = $result->option((string) $key);
                $actual = $option !== null && $option->available ? (string) \count($option->packages) : '–';
                $rows[] = ['case' => $id, 'title' => $title, 'check' => $key . ' Pakete', 'expected' => (string) $count, 'actual' => $actual, 'ok' => (string) $count === $actual];
            }
            foreach ((array) ($case['expectHints'] ?? []) as $key => $codes) {
                $option = $result->option((string) $key);
                foreach ((array) $codes as $code) {
                    $has = $option !== null && $option->hasHint((string) $code);
                    $rows[] = ['case' => $id, 'title' => $title, 'check' => $key . ' Hinweis', 'expected' => (string) $code, 'actual' => $has ? (string) $code : '–', 'ok' => $has];
                }
            }
            if (isset($case['expectRecommended'])) {
                $rec = '–';
                foreach ($result->options as $option) {
                    if ($option->recommended) {
                        $rec = $option->key;
                    }
                }
                $rows[] = ['case' => $id, 'title' => $title, 'check' => 'empfohlen', 'expected' => (string) $case['expectRecommended'], 'actual' => $rec, 'ok' => $rec === $case['expectRecommended']];
            }
        }

        return $rows;
    }

    /**
     * Kanalregeln aus einem einfachen Array (Testfälle, Skripte, spätere Portierung).
     *
     * @param array<string, mixed> $c
     */
    public static function rules(array $c): ChannelRules
    {
        $defaults = new ChannelRules();
        $date = static fn (mixed $v) => \is_string($v) && $v !== '' ? new \DateTimeImmutable($v) : null;

        return new ChannelRules(
            homeCountry: (string) ($c['homeCountry'] ?? 'DE'),
            vatPercent: (float) ($c['vatPercent'] ?? 19),
            fixedPrices: \is_array($c['fixedPrices'] ?? null) ? $c['fixedPrices'] : $defaults->fixedPrices,
            foreignSurcharge: (float) ($c['foreignSurcharge'] ?? 5),
            passOversize: (bool) ($c['passOversize'] ?? true),
            maxPackageKg: (float) ($c['maxPackageKg'] ?? 25),
            goodsValueLimits: \is_array($c['goodsValueLimits'] ?? null) ? $c['goodsValueLimits'] : $defaults->goodsValueLimits,
            passPeak: (bool) ($c['passPeak'] ?? true),
            peakInPeakFrom: $date($c['peakInPeakFrom'] ?? null),
            peakInPeakUntil: $date($c['peakInPeakUntil'] ?? null),
            energyPercent: \is_array($c['energyPercent'] ?? null) ? $c['energyPercent'] : [],
            offerDpdOutsideEu: (bool) ($c['offerDpdOutsideEu'] ?? true),
            allowedCountries: \is_array($c['allowedCountries'] ?? null) ? array_values($c['allowedCountries']) : [],
            freeCountries: \is_array($c['freeCountries'] ?? null) ? array_values($c['freeCountries']) : ['DE'],
            freightSurcharge: (float) ($c['freightSurcharge'] ?? 20),
            freightFallbackPrice: isset($c['freightFallbackPrice']) ? (float) $c['freightFallbackPrice'] : null,
            pickupEnabled: (bool) ($c['pickupEnabled'] ?? true),
            requestTriggers: \is_array($c['requestTriggers'] ?? null) ? array_values(array_map('strval', $c['requestTriggers'])) : [],
            smallNoDims: \is_string($c['smallNoDims'] ?? null) ? $c['smallNoDims'] : ChannelRules::SMALL_OFF,
            smallNoDimsMaxKg: (float) ($c['smallNoDimsMaxKg'] ?? 1),
            fixedPriceMaxKg: \is_array($c['fixedPriceMaxKg'] ?? null) ? $c['fixedPriceMaxKg'] : [],
            fixedOverMode: \is_string($c['fixedOverMode'] ?? null) ? $c['fixedOverMode'] : ChannelRules::FIXED_OVER_PURCHASE,
            fixedOverSurcharge: (float) ($c['fixedOverSurcharge'] ?? 0),
        );
    }
}
