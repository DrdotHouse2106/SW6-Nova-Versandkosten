<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tariff;

/**
 * Tarife als CSV (zum Bearbeiten in Excel/LibreOffice) ↔ JSON-Format.
 *
 * Eine Datei, eine Zeile je Eintrag; die Spalte „type“ sagt, was die Zeile ist:
 *
 * | type      | Pflichtspalten                                   | Bedeutung                                   |
 * |-----------|--------------------------------------------------|---------------------------------------------|
 * | meta      | name                                             | Name der Tarifdatei                         |
 * | carrier   | carrier, name                                    | Anzeigename eines Dienstleisters            |
 * | product   | product, carrier, option, name, (limits)         | Versandprodukt                              |
 * | rate      | product, zone, countries, max_kg, price_net      | Gewichtsstufe                               |
 * | rate      | product, zone, countries, price_net, per_kg_net, round_kg | Stückpreis + kg-Preis (statt Stufen) |
 * | rate      | product, zone, countries, price_net, per_100g_net | Stückpreis + Preis je angefangene 100 g |
 * | oversize  | product, name, amount_net, condition, (limits)   | Sperrgut-/Übermaß-Stufe                     |
 * | surcharge | product, name, kind, amount_net oder percent     | Zuschlag (optional countries, postcodes, months, pass_on) |
 *
 * Listen (countries, postcodes, months) mit Leerzeichen trennen. limits/condition als
 * „schlüssel=wert“-Paare mit Leerzeichen, Maße als „35.3x25x5“. Dezimaltrenner Komma oder Punkt.
 * In „product“ einer surcharge-/oversize-Zeile dürfen mehrere Produkt-IDs mit Leerzeichen stehen.
 * Trennzeichen „;“ (Excel deutsch) oder „,“ werden erkannt.
 */
final class TariffCsv
{
    public const COLUMNS = [
        'type', 'product', 'carrier', 'option', 'name', 'zone', 'countries', 'max_kg', 'price_net',
        'per_kg_net', 'per_100g_net', 'round_kg', 'amount_net', 'percent', 'kind', 'condition', 'limits', 'postcodes',
        'months', 'pass_on',
    ];

    /**
     * CSV-Text → Tarif-Array im JSON-Format (noch ungeprüft; danach TariffSet::fromArray).
     *
     * @return array<string, mixed>
     */
    public static function toArray(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l) => trim($l) !== ''));
        if ($lines === []) {
            throw new TariffException(['Die CSV-Datei ist leer.']);
        }

        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), str_getcsv($lines[0], $delimiter, '"', ''));
        if (!\in_array('type', $header, true) || !\in_array('product', $header, true)) {
            throw new TariffException(['Erste Zeile muss die Spaltenköpfe enthalten (mindestens „type“ und „product“).']);
        }

        $errors = [];
        $data = [
            'format' => TariffSet::FORMAT,
            'version' => TariffSet::VERSION,
            'name' => '',
            'currency' => 'EUR',
            'carriers' => [],
            'products' => [],
        ];
        /** @var array<string, array<string, mixed>> $products */
        $products = [];
        /** @var array<string, array<string, array<string, mixed>>> $zones */
        $zones = [];
        $pending = [];

        foreach (\array_slice($lines, 1) as $n => $line) {
            $lineNo = $n + 2;
            $cells = str_getcsv($line, $delimiter, '"', '');
            $row = [];
            foreach ($header as $i => $column) {
                $row[$column] = trim((string) ($cells[$i] ?? ''));
            }
            $type = strtolower($row['type'] ?? '');
            if ($type === '' || str_starts_with($type, '#')) {
                continue;
            }

            switch ($type) {
                case 'meta':
                    $data['name'] = $row['name'] ?? '';
                    break;

                case 'carrier':
                    if (($row['carrier'] ?? '') === '') {
                        $errors[] = \sprintf('Zeile %d: „carrier“ fehlt.', $lineNo);
                        break;
                    }
                    $data['carriers'][strtolower($row['carrier'])] = ['name' => $row['name'] !== '' ? $row['name'] : $row['carrier']];
                    break;

                case 'product':
                    $id = $row['product'] ?? '';
                    if ($id === '') {
                        $errors[] = \sprintf('Zeile %d: „product“ fehlt.', $lineNo);
                        break;
                    }
                    $products[$id] = [
                        'id' => $id,
                        'carrier' => strtolower($row['carrier'] ?? ''),
                        'option' => strtolower($row['option'] ?? ''),
                        'name' => ($row['name'] ?? '') !== '' ? $row['name'] : $id,
                        'limits' => self::pairs($row['limits'] ?? ''),
                        'zones' => [],
                        'oversize' => [],
                        'surcharges' => [],
                    ];
                    break;

                case 'rate':
                case 'oversize':
                case 'surcharge':
                    $pending[] = [$lineNo, $type, $row];
                    break;

                default:
                    $errors[] = \sprintf('Zeile %d: unbekannter type „%s“.', $lineNo, $type);
            }
        }

        foreach ($pending as [$lineNo, $type, $row]) {
            $ids = preg_split('/\s+/', $row['product'] ?? '', -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if ($ids === []) {
                $errors[] = \sprintf('Zeile %d: „product“ fehlt.', $lineNo);
                continue;
            }
            foreach ($ids as $id) {
                if (!isset($products[$id])) {
                    $errors[] = \sprintf('Zeile %d: Produkt „%s“ ist nicht definiert (type=product-Zeile fehlt).', $lineNo, $id);
                    continue;
                }

                if ($type === 'rate') {
                    $zoneName = ($row['zone'] ?? '') !== '' ? $row['zone'] : ($row['countries'] ?? '');
                    if (!isset($zones[$id][$zoneName])) {
                        $zones[$id][$zoneName] = ['name' => $zoneName, 'countries' => self::list($row['countries'] ?? '')];
                    }
                    if (($row['max_kg'] ?? '') !== '') {
                        $zones[$id][$zoneName]['steps'][] = ['maxKg' => self::num($row['max_kg']), 'price' => self::num($row['price_net'] ?? '')];
                    } else {
                        $zones[$id][$zoneName]['perPiece'] = self::num($row['price_net'] ?? '');
                        if (($row['per_100g_net'] ?? '') !== '') {
                            $zones[$id][$zoneName]['per100g'] = self::num($row['per_100g_net']);
                            continue;
                        }
                        $zones[$id][$zoneName]['perKg'] = self::num($row['per_kg_net'] ?? '');
                        if (($row['round_kg'] ?? '') !== '') {
                            $zones[$id][$zoneName]['roundKg'] = self::num($row['round_kg']);
                        }
                    }
                } elseif ($type === 'oversize') {
                    $tier = [
                        'name' => $row['name'] ?? 'Übermaß',
                        'amount' => self::num($row['amount_net'] ?? ''),
                        'if' => self::pairs($row['condition'] ?? ''),
                    ];
                    $max = self::pairs($row['limits'] ?? '');
                    if ($max !== []) {
                        $tier['max'] = $max;
                    }
                    $products[$id]['oversize'][] = $tier;
                } else {
                    $kind = ($row['kind'] ?? '') !== '' ? $row['kind'] : 'fixed';
                    $s = ['type' => $kind, 'name' => $row['name'] ?? $kind];
                    if ($kind === 'percent') {
                        $s['percent'] = self::num($row['percent'] ?? '');
                    } elseif ($kind !== 'energy') {
                        $s['amount'] = self::num($row['amount_net'] ?? '');
                    }
                    if (($row['countries'] ?? '') !== '') {
                        $s['countries'] = self::list($row['countries']);
                    }
                    if (($row['postcodes'] ?? '') !== '') {
                        $s['postcodes'] = preg_split('/\s+/', $row['postcodes'], -1, \PREG_SPLIT_NO_EMPTY) ?: [];
                    }
                    if (($row['months'] ?? '') !== '') {
                        $s['months'] = array_map('intval', preg_split('/\s+/', $row['months'], -1, \PREG_SPLIT_NO_EMPTY) ?: []);
                    }
                    if (\in_array(strtolower($row['pass_on'] ?? ''), ['1', 'ja', 'yes', 'true', 'x'], true)) {
                        $s['passOn'] = true;
                    }
                    $products[$id]['surcharges'][] = $s;
                }
            }
        }

        if ($errors !== []) {
            throw new TariffException($errors);
        }

        foreach ($products as $id => $product) {
            $product['zones'] = array_values($zones[$id] ?? []);
            if ($product['oversize'] === []) {
                unset($product['oversize']);
            }
            if ($product['surcharges'] === []) {
                unset($product['surcharges']);
            }
            $data['products'][] = $product;
        }
        if ($data['carriers'] === []) {
            unset($data['carriers']);
        }

        return $data;
    }

    /**
     * Tarif-Array (JSON-Format) → CSV-Text mit „;“ und Dezimalkomma (Excel deutsch).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): string
    {
        $rows = [self::COLUMNS];
        $row = static function (array $values): array {
            $out = [];
            foreach (self::COLUMNS as $column) {
                $out[] = (string) ($values[$column] ?? '');
            }

            return $out;
        };

        if (($data['name'] ?? '') !== '') {
            $rows[] = $row(['type' => 'meta', 'name' => $data['name']]);
        }
        foreach ((array) ($data['carriers'] ?? []) as $key => $carrier) {
            $rows[] = $row(['type' => 'carrier', 'carrier' => $key, 'name' => \is_array($carrier) ? ($carrier['name'] ?? $key) : $carrier]);
        }

        foreach ((array) ($data['products'] ?? []) as $p) {
            $id = (string) $p['id'];
            $rows[] = $row([
                'type' => 'product', 'product' => $id, 'carrier' => $p['carrier'] ?? '', 'option' => $p['option'] ?? '',
                'name' => $p['name'] ?? '', 'limits' => self::pairsToString((array) ($p['limits'] ?? [])),
            ]);
            foreach ((array) ($p['zones'] ?? []) as $zone) {
                $countries = implode(' ', (array) ($zone['countries'] ?? []));
                $zoneName = (string) ($zone['name'] ?? '');
                if (isset($zone['steps'])) {
                    foreach ((array) $zone['steps'] as $step) {
                        $rows[] = $row(['type' => 'rate', 'product' => $id, 'zone' => $zoneName, 'countries' => $countries,
                            'max_kg' => self::fmt($step['maxKg']), 'price_net' => self::fmt($step['price'])]);
                    }
                } else {
                    if (isset($zone['per100g'])) {
                        $rows[] = $row(['type' => 'rate', 'product' => $id, 'zone' => $zoneName, 'countries' => $countries,
                            'price_net' => self::fmt($zone['perPiece'] ?? ''), 'per_100g_net' => self::fmt($zone['per100g'])]);
                        continue;
                    }
                    $rows[] = $row(['type' => 'rate', 'product' => $id, 'zone' => $zoneName, 'countries' => $countries,
                        'price_net' => self::fmt($zone['perPiece'] ?? ''), 'per_kg_net' => self::fmt($zone['perKg'] ?? ''),
                        'round_kg' => self::fmt($zone['roundKg'] ?? 0.1)]);
                }
            }
            foreach ((array) ($p['oversize'] ?? []) as $tier) {
                $rows[] = $row(['type' => 'oversize', 'product' => $id, 'name' => $tier['name'] ?? '',
                    'amount_net' => self::fmt($tier['amount'] ?? ''), 'condition' => self::pairsToString((array) ($tier['if'] ?? [])),
                    'limits' => self::pairsToString((array) ($tier['max'] ?? []))]);
            }
            foreach ((array) ($p['surcharges'] ?? []) as $s) {
                $rows[] = $row(['type' => 'surcharge', 'product' => $id, 'name' => $s['name'] ?? '', 'kind' => $s['type'] ?? 'fixed',
                    'amount_net' => isset($s['amount']) ? self::fmt($s['amount']) : '', 'percent' => isset($s['percent']) ? self::fmt($s['percent']) : '',
                    'countries' => implode(' ', (array) ($s['countries'] ?? [])), 'postcodes' => implode(' ', (array) ($s['postcodes'] ?? [])),
                    'months' => isset($s['months']) ? implode(' ', (array) $s['months']) : '', 'pass_on' => !empty($s['passOn']) ? '1' : '']);
            }
        }

        $out = '';
        foreach ($rows as $r) {
            $out .= implode(';', array_map(static function (string $cell): string {
                return preg_match('/[;"\n]/', $cell) ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
            }, $r)) . "\r\n";
        }

        return $out;
    }

    private static function fmt(mixed $value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }
        $s = rtrim(rtrim(number_format((float) $value, 4, ',', ''), '0'), ',');

        return $s === '' || $s === '-' ? '0' : $s;
    }

    private static function num(string $value): float|string
    {
        $v = str_replace(',', '.', trim($value));

        return is_numeric($v) ? (float) $v : $value;
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_map('strtoupper', preg_split('/[\s,]+/', trim($value), -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * „maxKg=31,5 maxDimsCm=35.3x25x5 singlePackage=1“ → Array
     *
     * @return array<string, mixed>
     */
    private static function pairs(string $value): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($value), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$key, $v] = explode('=', $pair, 2);
            if (preg_match('/^[\d.,]+x[\d.,]+x[\d.,]+$/i', $v)) {
                $out[$key] = array_map(static fn ($n) => (float) str_replace(',', '.', $n), explode('x', strtolower($v)));
            } elseif (\in_array(strtolower($v), ['ja', 'true', 'yes'], true) || ($v === '1' && \in_array($key, ['singlePackage', 'allowBulky', 'basicTracking', 'bulky'], true))) {
                $out[$key] = true;
            } elseif (\in_array(strtolower($v), ['nein', 'false', 'no'], true) || ($v === '0' && \in_array($key, ['singlePackage', 'allowBulky', 'basicTracking', 'bulky'], true))) {
                $out[$key] = false;
            } else {
                $out[$key] = self::num($v);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $pairs */
    private static function pairsToString(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as $key => $value) {
            if (\is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (\is_array($value)) {
                $value = implode('x', array_map(static fn ($v) => self::fmt($v), $value));
            } else {
                $value = self::fmt($value);
            }
            $parts[] = $key . '=' . $value;
        }

        return implode(' ', $parts);
    }
}
