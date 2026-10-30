<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tariff;

use Nova\Versandkosten\Calculator\Money;

/**
 * Alle Einkaufstarife (eine importierte Tarifdatei). Format: siehe README „Tarifformat“ und
 * examples/tarife-beispiel.json. Beträge in der Datei: Euro netto mit Punkt als Dezimaltrenner.
 */
final class TariffSet
{
    public const FORMAT = 'nova-versandkosten-tarife';
    public const VERSION = 1;

    /**
     * @param list<TariffProduct> $products
     * @param array<string, string> $carrierNames Dienstleister-Schlüssel → Anzeigename
     * @param array<string, mixed> $data die geprüfte Rohfassung (für Speicherung/Export)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $products,
        public readonly array $carrierNames,
        public readonly array $data,
    ) {
    }

    public function carrierName(string $carrier): string
    {
        return $this->carrierNames[$carrier] ?? strtoupper($carrier);
    }

    /** @return list<TariffProduct> */
    public function productsFor(string $option, string $country): array
    {
        return array_values(array_filter(
            $this->products,
            static fn (TariffProduct $p) => $p->option === $option && $p->zoneFor($country) !== null
        ));
    }

    /** @return list<string> Optionen mit mindestens einem Produkt */
    public function options(): array
    {
        $options = [];
        foreach (TariffProduct::OPTIONS as $option) {
            foreach ($this->products as $product) {
                if ($product->option === $option) {
                    $options[] = $option;
                    break;
                }
            }
        }

        return $options;
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TariffException(['Kein gültiges JSON: ' . $e->getMessage()]);
        }
        if (!\is_array($data)) {
            throw new TariffException(['Die Tarifdatei muss ein JSON-Objekt sein.']);
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (($data['format'] ?? null) !== self::FORMAT) {
            $errors[] = \sprintf('„format“ muss „%s“ sein.', self::FORMAT);
        }
        if ((int) ($data['version'] ?? 0) !== self::VERSION) {
            $errors[] = \sprintf('„version“ muss %d sein.', self::VERSION);
        }
        if (isset($data['currency']) && $data['currency'] !== 'EUR') {
            $errors[] = 'Nur Tarife in EUR werden unterstützt.';
        }

        $carrierNames = [];
        if (isset($data['carriers']) && \is_array($data['carriers'])) {
            foreach ($data['carriers'] as $key => $carrier) {
                $carrierNames[(string) $key] = \is_array($carrier) ? (string) ($carrier['name'] ?? $key) : (string) $carrier;
            }
        }

        $products = [];
        $ids = [];
        $rawProducts = $data['products'] ?? null;
        if (!\is_array($rawProducts) || $rawProducts === []) {
            $errors[] = '„products“ fehlt oder ist leer.';
            $rawProducts = [];
        }

        foreach (array_values($rawProducts) as $i => $raw) {
            $where = \sprintf('Produkt %d', $i + 1);
            if (!\is_array($raw)) {
                $errors[] = $where . ': muss ein Objekt sein.';
                continue;
            }
            $id = (string) ($raw['id'] ?? '');
            if ($id !== '') {
                $where = \sprintf('Produkt „%s“', $id);
            }
            $product = self::parseProduct($raw, $where, $errors);
            if ($product === null) {
                continue;
            }
            if (isset($ids[$product->id])) {
                $errors[] = $where . ': Produkt-ID doppelt.';
            }
            $ids[$product->id] = true;
            $products[] = $product;
        }

        if ($errors !== []) {
            throw new TariffException($errors);
        }

        return new self((string) ($data['name'] ?? ''), $products, $carrierNames, $data);
    }

    /**
     * @param array<mixed> $raw
     * @param list<string> $errors
     */
    private static function parseProduct(array $raw, string $where, array &$errors): ?TariffProduct
    {
        $before = \count($errors);
        $id = trim((string) ($raw['id'] ?? ''));
        $carrier = strtolower(trim((string) ($raw['carrier'] ?? '')));
        $option = strtolower(trim((string) ($raw['option'] ?? '')));

        if ($id === '' || !preg_match('/^[a-z0-9_\-]+$/i', $id)) {
            $errors[] = $where . ': „id“ fehlt oder enthält ungültige Zeichen (erlaubt: a–z, 0–9, _ und -).';
        }
        if ($carrier === '' || !preg_match('/^[a-z0-9_\-]+$/', $carrier)) {
            $errors[] = $where . ': „carrier“ fehlt (z. B. dhl, dpd, post).';
        }
        if (!\in_array($option, TariffProduct::OPTIONS, true)) {
            $errors[] = $where . ': „option“ muss eins von ' . implode(', ', TariffProduct::OPTIONS) . ' sein.';
        }

        $limits = \is_array($raw['limits'] ?? null) ? $raw['limits'] : [];
        $maxKg = self::number($limits['maxKg'] ?? 31.5, $where . ' limits.maxKg', $errors) ?? 31.5;
        $maxDims = null;
        if (isset($limits['maxDimsCm'])) {
            $maxDims = self::dims($limits['maxDimsCm'], $where . ' limits.maxDimsCm', $errors);
        }

        $zones = [];
        $rawZones = $raw['zones'] ?? null;
        if (!\is_array($rawZones) || $rawZones === []) {
            $errors[] = $where . ': „zones“ fehlt oder ist leer.';
            $rawZones = [];
        }
        foreach (array_values($rawZones) as $zi => $rawZone) {
            $zone = self::parseZone($rawZone, \sprintf('%s Zone %d', $where, $zi + 1), $errors);
            if ($zone !== null) {
                $zones[] = $zone;
            }
        }

        $oversize = [];
        foreach (array_values(\is_array($raw['oversize'] ?? null) ? $raw['oversize'] : []) as $ti => $rawTier) {
            $w = \sprintf('%s Übermaß-Stufe %d', $where, $ti + 1);
            if (!\is_array($rawTier)) {
                $errors[] = $w . ': muss ein Objekt sein.';
                continue;
            }
            $amount = self::number($rawTier['amount'] ?? null, $w . ' amount', $errors);
            $condition = \is_array($rawTier['if'] ?? null) ? $rawTier['if'] : [];
            if ($condition === []) {
                $errors[] = $w . ': Bedingung „if“ fehlt.';
            }
            $known = ['lengthOver', 'lengthAtLeast', 'sideOver', 'volumeOverL', 'girthOver', 'exceedsDimsCm', 'weightOverKg', 'bulky'];
            foreach (array_keys($condition) as $key) {
                if (!\in_array($key, $known, true)) {
                    $errors[] = \sprintf('%s: unbekannte Bedingung „%s“.', $w, $key);
                }
            }
            $max = \is_array($rawTier['max'] ?? null) ? $rawTier['max'] : [];
            foreach (array_keys($max) as $key) {
                if (!\in_array($key, ['lengthCm', 'girthCm', 'dimsCm', 'kg'], true)) {
                    $errors[] = \sprintf('%s: unbekanntes Höchstmaß „%s“.', $w, $key);
                }
            }
            if ($amount !== null) {
                $oversize[] = new OversizeTier((string) ($rawTier['name'] ?? 'Übermaß'), Money::fromEuro($amount), $condition, $max);
            }
        }

        $surcharges = [];
        foreach (array_values(\is_array($raw['surcharges'] ?? null) ? $raw['surcharges'] : []) as $si => $rawS) {
            $w = \sprintf('%s Zuschlag %d', $where, $si + 1);
            if (!\is_array($rawS)) {
                $errors[] = $w . ': muss ein Objekt sein.';
                continue;
            }
            $type = (string) ($rawS['type'] ?? 'fixed');
            if (!\in_array($type, Surcharge::TYPES, true)) {
                $errors[] = \sprintf('%s: unbekannte Art „%s“ (erlaubt: %s).', $w, $type, implode(', ', Surcharge::TYPES));
                continue;
            }
            $amount = 0;
            $percent = 0.0;
            if (\in_array($type, ['fixed', 'peak', 'peakInPeak'], true)) {
                $amount = Money::fromEuro(self::number($rawS['amount'] ?? null, $w . ' amount', $errors) ?? 0);
            } elseif ($type === 'percent') {
                $percent = self::number($rawS['percent'] ?? null, $w . ' percent', $errors) ?? 0.0;
            }
            $months = [11, 12];
            if (isset($rawS['months']) && \is_array($rawS['months'])) {
                $months = array_values(array_map('intval', $rawS['months']));
            }
            $surcharges[] = new Surcharge(
                $type,
                (string) ($rawS['name'] ?? $type),
                $amount,
                $percent,
                self::countries($rawS['countries'] ?? []),
                array_values(array_map(static fn ($p) => trim((string) $p), \is_array($rawS['postcodes'] ?? null) ? $rawS['postcodes'] : [])),
                $months,
                (bool) ($rawS['passOn'] ?? false),
            );
        }

        if (\count($errors) > $before) {
            return null;
        }

        $optionalNumber = static function (mixed $value, string $label) use (&$errors): ?float {
            return $value === null ? null : self::number($value, $label, $errors);
        };

        return new TariffProduct(
            $id,
            $carrier,
            $option,
            (string) ($raw['name'] ?? $id),
            $zones,
            $maxKg,
            $maxDims,
            $optionalNumber($limits['maxLengthCm'] ?? null, $where . ' limits.maxLengthCm'),
            $optionalNumber($limits['maxGirthCm'] ?? null, $where . ' limits.maxGirthCm'),
            $optionalNumber($limits['maxGoodsValue'] ?? null, $where . ' limits.maxGoodsValue'),
            (bool) ($limits['singlePackage'] ?? false),
            (bool) ($limits['allowBulky'] ?? true),
            (bool) ($limits['basicTracking'] ?? false),
            $oversize,
            $surcharges,
        );
    }

    /**
     * @param list<string> $errors
     */
    private static function parseZone(mixed $raw, string $where, array &$errors): ?TariffZone
    {
        if (!\is_array($raw)) {
            $errors[] = $where . ': muss ein Objekt sein.';

            return null;
        }
        $countries = self::countries($raw['countries'] ?? []);
        if ($countries === []) {
            $errors[] = $where . ': „countries“ fehlt (ISO-Codes wie DE, AT).';
        }
        foreach ($countries as $country) {
            if (!preg_match('/^[A-Z]{2}$/', $country)) {
                $errors[] = \sprintf('%s: Ländercode „%s“ ungültig.', $where, $country);
            }
        }

        $steps = [];
        if (isset($raw['steps'])) {
            if (!\is_array($raw['steps']) || $raw['steps'] === []) {
                $errors[] = $where . ': „steps“ ist leer.';
            } else {
                $last = 0.0;
                foreach (array_values($raw['steps']) as $si => $step) {
                    $max = \is_array($step) ? self::number($step['maxKg'] ?? null, $where . ' Stufe ' . ($si + 1) . ' maxKg', $errors) : null;
                    $price = \is_array($step) ? self::number($step['price'] ?? null, $where . ' Stufe ' . ($si + 1) . ' price', $errors) : null;
                    if ($max === null || $price === null) {
                        continue;
                    }
                    if ($max <= $last) {
                        $errors[] = $where . ': Gewichtsstufen müssen aufsteigend sein.';
                    }
                    $last = $max;
                    $steps[] = ['maxKg' => $max, 'price' => Money::fromEuro($price)];
                }
            }

            return new TariffZone((string) ($raw['name'] ?? ''), $countries, $steps);
        }

        $perPiece = self::number($raw['perPiece'] ?? null, $where . ' perPiece', $errors);
        $perKg = null;
        $roundKg = 0.1;
        if (isset($raw['per100g'])) {
            $per100g = self::number($raw['per100g'], $where . ' per100g', $errors);
            $perKg = $per100g === null ? null : $per100g * 10;
        } elseif (isset($raw['perKg'])) {
            $perKg = self::number($raw['perKg'], $where . ' perKg', $errors);
            $roundKg = self::number($raw['roundKg'] ?? 0.1, $where . ' roundKg', $errors) ?? 0.1;
        } else {
            $errors[] = $where . ': entweder „steps“ oder „perPiece“ + „perKg“/„per100g“ angeben.';
        }
        if ($perPiece === null || $perKg === null) {
            return null;
        }

        return new TariffZone((string) ($raw['name'] ?? ''), $countries, [], Money::fromEuro($perPiece), Money::fromEuro($perKg), $roundKg > 0 ? $roundKg : 0.1);
    }

    /**
     * @param list<string> $errors
     */
    private static function number(mixed $value, string $label, array &$errors): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        if (\is_string($value) && is_numeric(str_replace(',', '.', trim($value)))) {
            return (float) str_replace(',', '.', trim($value));
        }
        $errors[] = \sprintf('%s: Zahl erwartet.', $label);

        return null;
    }

    /**
     * @param list<string> $errors
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function dims(mixed $value, string $label, array &$errors): ?array
    {
        if (\is_string($value)) {
            $value = preg_split('/\s*[x×*]\s*/u', $value) ?: [];
        }
        if (!\is_array($value) || \count($value) !== 3) {
            $errors[] = $label . ': drei Maße erwartet, z. B. [35.3, 25, 5].';

            return null;
        }
        $out = [];
        foreach (array_values($value) as $v) {
            $n = self::number($v, $label, $errors);
            if ($n === null) {
                return null;
            }
            $out[] = $n;
        }
        rsort($out);

        return [$out[0], $out[1], $out[2]];
    }

    /** @return list<string> */
    private static function countries(mixed $value): array
    {
        if (\is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($c) => strtoupper(trim((string) $c)), $value), static fn ($c) => $c !== ''));
    }
}
