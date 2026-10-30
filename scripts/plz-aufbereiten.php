<?php declare(strict_types=1);

/**
 * Bereitet die GeoNames-Postleitzahlen (https://download.geonames.org/export/zip/DE.zip, Datei DE.txt,
 * Lizenz CC BY 4.0) zu der kompakten Datei auf, die das Plugin mitliefert:
 *
 *   php scripts/plz-aufbereiten.php DE.txt src/Resources/geo/postcodes-de.tsv.gz
 *
 * Ausgabe (gzip, UTF-8, Tabulator): Land, PLZ, Ort, Bundesland, Breite, Länge – je (PLZ, Ort) eine Zeile,
 * sortiert. Einträge von Großkunden-PLZ (Firmennamen statt Orten) werden als Ort verworfen; die PLZ
 * bleibt mit ihrer Koordinate erhalten (Ort leer), damit Lieferadressen mit dieser PLZ gefunden werden.
 */
if ($argc < 3) {
    fwrite(STDERR, "Aufruf: php scripts/plz-aufbereiten.php DE.txt ausgabe.tsv.gz\n");
    exit(1);
}

$in = fopen($argv[1], 'r');
if ($in === false) {
    fwrite(STDERR, "Datei nicht lesbar: {$argv[1]}\n");
    exit(1);
}

$company = '/\b(GmbH|mbH|AG|KG|KGaA|SE|e\.\s?V\.|Co\.|GbR|OHG|Bank|Sparkasse|Versicherung|Verlag|Postfach|Gesellschaft|Holding|Vertrieb|Service|Stiftung|Werke?|Universit|Klinik|Ministerium|Behörde|Bundes|Landes)\b/u';
$rows = [];
$byPostcode = [];
while (($line = fgets($in)) !== false) {
    $f = explode("\t", rtrim($line, "\r\n"));
    if (\count($f) < 11 || !is_numeric($f[9]) || !is_numeric($f[10])) {
        continue;
    }
    [$country, $postcode, $place, $state] = [$f[0], $f[1], trim($f[2]), $f[3]];
    $lat = round((float) $f[9], 4);
    $lon = round((float) $f[10], 4);
    $byPostcode[$country . '|' . $postcode][] = [$lat, $lon, $state];
    if ($place === '' || preg_match($company, $place) === 1) {
        continue;
    }
    $rows[$country . '|' . $postcode . '|' . $place] = [$country, $postcode, $place, $state, $lat, $lon];
}
fclose($in);

// PLZ ohne echten Ort (nur Großkunden): Mittelpunkt mit leerem Ort behalten
foreach ($byPostcode as $key => $points) {
    [$country, $postcode] = explode('|', $key);
    $has = false;
    foreach (array_keys($rows) as $rowKey) {
        if (str_starts_with($rowKey, $key . '|')) {
            $has = true;
            break;
        }
    }
    if (!$has) {
        $lat = round(array_sum(array_column($points, 0)) / \count($points), 4);
        $lon = round(array_sum(array_column($points, 1)) / \count($points), 4);
        $rows[$key . '|'] = [$country, $postcode, '', $points[0][2], $lat, $lon];
    }
}
ksort($rows, \SORT_STRING);

$out = '';
foreach ($rows as $r) {
    $out .= implode("\t", [$r[0], $r[1], $r[2], $r[3], number_format($r[4], 4, '.', ''), number_format($r[5], 4, '.', '')]) . "\n";
}
file_put_contents($argv[2], gzencode($out, 9));
printf("%d Zeilen, %d PLZ → %s (%d Bytes)\n", \count($rows), \count($byPostcode), $argv[2], filesize($argv[2]));
