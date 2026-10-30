<?php declare(strict_types=1);

namespace Nova\Versandkosten\Energy;

/**
 * Liest die öffentlichen Energiezuschlag-Tabellen von DHL und DPD (HTML → Einträge „gültig ab“ + %).
 *
 * Arbeitet über das DOM (Tabellen, Kopfzeilen nach Text gesucht) statt über feste Positionen, damit
 * kleine Layoutänderungen nichts kaputt machen. Unerwarteter Inhalt → EnergyParseException.
 *
 * - DHL (dhl.de/energiezuschlag): Tabelle „Monat | Zuschlagshöhe ab 1./11./21. des Monats“ = Gesamtwert
 *   (langfristige + kurzfristige Komponente). Fehlt sie, werden die Tabellen „Langfristige Komponente“
 *   und „Kurzfristige Komponente ab 1./11./21.“ addiert.
 * - DPD (dpd.de/energiezuschlag): „Gültig für DPD Pakete ab | Energiezuschlag Straße | Luft | CLASSIC
 *   Weltweit | Veröffentlicht am“ – verwendet wird „Straße“.
 * - DPD Emergency Fuel Surcharge (eigene Seite): „Gültig für DPD Pakete ab | Zuschlag | …“.
 */
final class EnergySurchargeParser
{
    private const MONTHS = [
        'jan' => 1, 'januar' => 1, 'feb' => 2, 'februar' => 2, 'mär' => 3, 'mrz' => 3, 'märz' => 3, 'maerz' => 3,
        'apr' => 4, 'april' => 4, 'mai' => 5, 'jun' => 6, 'juni' => 6, 'jul' => 7, 'juli' => 7, 'aug' => 8,
        'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'okt' => 10, 'oktober' => 10, 'nov' => 11,
        'november' => 11, 'dez' => 12, 'dezember' => 12,
    ];

    /** @return list<EnergyEntry> */
    public static function parseDhl(string $html): array
    {
        $tables = self::tables($html);
        $long = [];
        $short = [];
        $total = [];

        foreach ($tables as $rows) {
            $headerIndex = self::findHeader($rows, static fn (array $cells) => self::contains($cells[0] ?? '', 'monat') && \count($cells) >= 2);
            if ($headerIndex === null) {
                continue;
            }
            $header = array_map(static fn (string $c) => mb_strtolower($c), $rows[$headerIndex]);
            $joined = implode(' | ', $header);
            $days = [];
            foreach ($header as $i => $cell) {
                if ($i === 0) {
                    continue;
                }
                $days[$i] = preg_match('/ab\s*(\d{1,2})\./u', $cell, $m) ? (int) $m[1] : 1;
            }

            $target = null;
            if (str_contains($joined, 'kurzfristig')) {
                $target = 'short';
            } elseif (str_contains($joined, 'langfristig')) {
                $target = 'long';
            } elseif (str_contains($joined, 'zuschlag')) {
                $target = 'total';
            }
            if ($target === null) {
                continue;
            }

            foreach (\array_slice($rows, $headerIndex + 1) as $cells) {
                $month = self::month($cells[0] ?? '');
                if ($month === null) {
                    continue;
                }
                foreach ($days as $i => $day) {
                    $value = self::percent($cells[$i] ?? '');
                    if ($value === null) {
                        continue;
                    }
                    $date = \sprintf('%04d-%02d-%02d', $month[0], $month[1], $day);
                    match ($target) {
                        'short' => $short[$date] = $value,
                        'long' => $long[$date] = $value,
                        default => $total[$date] = $value,
                    };
                }
            }
        }

        $entries = [];
        if ($total !== []) {
            foreach ($total as $date => $value) {
                $entries[] = new EnergyEntry($date, $value, self::dhlComponents($date, $long, $short));
            }
        } elseif ($long !== [] && $short !== []) {
            // Gesamtwert fehlt: je Stichtag der kurzfristigen Komponente den Monatswert der langfristigen addieren
            foreach ($short as $date => $value) {
                $components = self::dhlComponents($date, $long, $short);
                if (!isset($components['langfristig'])) {
                    continue;
                }
                $entries[] = new EnergyEntry($date, round($components['langfristig'] + $value, 2), $components);
            }
        }

        if ($entries === []) {
            throw new EnergyParseException('DHL: Tabelle „Zuschlagshöhe je Monat“ nicht gefunden – Seite geändert?');
        }

        return self::sorted($entries);
    }

    /** @return list<EnergyEntry> Energiezuschlag Straße */
    public static function parseDpd(string $html): array
    {
        $entries = [];
        foreach (self::tables($html) as $rows) {
            $headerIndex = self::findHeader($rows, static fn (array $cells) => self::contains($cells[0] ?? '', 'gültig') && self::anyContains($cells, 'straße'));
            if ($headerIndex === null) {
                continue;
            }
            $header = $rows[$headerIndex];
            $road = self::column($header, 'straße');
            $air = self::column($header, 'luft');
            $world = self::column($header, 'weltweit');
            foreach (\array_slice($rows, $headerIndex + 1) as $cells) {
                $date = self::date($cells[0] ?? '');
                $value = $road === null ? null : self::percent($cells[$road] ?? '');
                if ($date === null || $value === null) {
                    continue;
                }
                $components = ['strasse' => $value];
                foreach (['luft' => $air, 'weltweit' => $world] as $name => $index) {
                    $v = $index === null ? null : self::percent($cells[$index] ?? '');
                    if ($v !== null) {
                        $components[$name] = $v;
                    }
                }
                $entries[$date] = new EnergyEntry($date, $value, $components);
            }
        }
        if ($entries === []) {
            throw new EnergyParseException('DPD: Tabelle „Gültig für DPD Pakete ab / Energiezuschlag Straße“ nicht gefunden – Seite geändert?');
        }

        return self::sorted(array_values($entries));
    }

    /** @return list<EnergyEntry> Emergency Fuel Surcharge */
    public static function parseDpdEmergency(string $html): array
    {
        $entries = [];
        foreach (self::tables($html) as $rows) {
            $headerIndex = self::findHeader($rows, static fn (array $cells) => self::contains($cells[0] ?? '', 'gültig') && self::anyContains($cells, 'zuschlag'));
            if ($headerIndex === null) {
                continue;
            }
            $index = null;
            foreach ($rows[$headerIndex] as $i => $cell) {
                if ($i > 0 && str_starts_with(mb_strtolower($cell), 'zuschlag')) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                continue;
            }
            foreach (\array_slice($rows, $headerIndex + 1) as $cells) {
                $date = self::date($cells[0] ?? '');
                $value = self::percent($cells[$index] ?? '');
                if ($date !== null && $value !== null) {
                    $entries[$date] = new EnergyEntry($date, $value);
                }
            }
        }
        if ($entries === []) {
            throw new EnergyParseException('DPD Emergency Fuel Surcharge: Tabelle nicht gefunden – Seite geändert?');
        }

        return self::sorted(array_values($entries));
    }

    /** „31,00%“, „4,75 %“, „3.5 %“ → float; leer/ungültig → null */
    public static function percent(string $text): ?float
    {
        $text = trim(str_replace(["\u{00A0}", ' '], '', $text));
        if (!preg_match('/^(\d{1,3}(?:[.,]\d{1,4})?)%?$/u', $text, $m)) {
            return null;
        }

        return (float) str_replace(',', '.', $m[1]);
    }

    /** „01.11.2026“ → „2026-11-01“ */
    public static function date(string $text): ?string
    {
        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $m) || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return \sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }

    /**
     * „Nov 2026“, „November 2026“ → [2026, 11]
     *
     * @return array{0: int, 1: int}|null
     */
    public static function month(string $text): ?array
    {
        if (!preg_match('/^([a-zäöü]+)\.?\s+(\d{4})$/iu', trim(str_replace("\u{00A0}", ' ', $text)), $m)) {
            return null;
        }
        $month = self::MONTHS[mb_strtolower($m[1])] ?? null;

        return $month === null ? null : [(int) $m[2], $month];
    }

    /**
     * Alle Tabellen als Zeilen mit Zellentexten (th und td gleichwertig).
     *
     * @return list<list<list<string>>>
     */
    public static function tables(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $tables = [];
        foreach ($dom->getElementsByTagName('table') as $table) {
            $rows = [];
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $cell) {
                    if ($cell instanceof \DOMElement && \in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                        $cells[] = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $cell->textContent)));
                    }
                }
                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }
            if ($rows !== []) {
                $tables[] = $rows;
            }
        }

        return $tables;
    }

    /**
     * @param array<string, float> $long
     * @param array<string, float> $short
     *
     * @return array<string, float>
     */
    private static function dhlComponents(string $date, array $long, array $short): array
    {
        $components = [];
        $monthStart = substr($date, 0, 8) . '01';
        if (isset($long[$monthStart])) {
            $components['langfristig'] = $long[$monthStart];
        }
        $shortValue = null;
        foreach ($short as $d => $v) {
            if ($d <= $date && str_starts_with($d, substr($date, 0, 8))) {
                $shortValue = $v;
            }
        }
        if ($shortValue !== null) {
            $components['kurzfristig'] = $shortValue;
        }

        return $components;
    }

    /**
     * @param list<list<string>> $rows
     * @param callable(list<string>): bool $match
     */
    private static function findHeader(array $rows, callable $match): ?int
    {
        foreach (\array_slice($rows, 0, 4, true) as $i => $cells) {
            if ($match($cells)) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<string> $header */
    private static function column(array $header, string $needle): ?int
    {
        foreach ($header as $i => $cell) {
            if ($i > 0 && self::contains($cell, $needle)) {
                return $i;
            }
        }

        return null;
    }

    private static function contains(string $text, string $needle): bool
    {
        return str_contains(mb_strtolower($text), $needle);
    }

    /** @param list<string> $cells */
    private static function anyContains(array $cells, string $needle): bool
    {
        foreach ($cells as $cell) {
            if (self::contains($cell, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<EnergyEntry> $entries
     *
     * @return list<EnergyEntry>
     */
    private static function sorted(array $entries): array
    {
        usort($entries, static fn (EnergyEntry $a, EnergyEntry $b) => strcmp($a->validFrom, $b->validFrom));

        return $entries;
    }
}
