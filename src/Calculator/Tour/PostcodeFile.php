<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Mitgelieferte PLZ-Datei (GeoNames, CC BY 4.0 – siehe NOTICE): gzip, Tabulator, Zeilen
 * „Land, PLZ, Ort, Bundesland, Breite, Länge“. Aufbereitet mit scripts/plz-aufbereiten.php.
 */
final class PostcodeFile
{
    public const PATH = __DIR__ . '/../../Resources/geo/postcodes-de.tsv.gz';

    public static function normalize(string $postcode): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($postcode)));
    }

    /**
     * Zeilen lesen; fehlerhafte überspringen.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: float, 5: float}>
     */
    public static function parse(string $data): array
    {
        $rows = [];
        foreach (explode("\n", $data) as $line) {
            $f = explode("\t", rtrim($line, "\r"));
            if (\count($f) < 6 || \strlen($f[0]) !== 2 || trim($f[1]) === '' || !is_numeric($f[4]) || !is_numeric($f[5])) {
                continue;
            }
            $rows[] = [strtoupper($f[0]), self::normalize($f[1]), mb_substr($f[2], 0, 180), mb_substr($f[3], 0, 100), (float) $f[4], (float) $f[5]];
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: float, 5: float}>
     */
    public static function read(string $path = self::PATH): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = \is_string($raw) ? gzdecode($raw) : false;

        return \is_string($data) ? self::parse($data) : [];
    }
}
