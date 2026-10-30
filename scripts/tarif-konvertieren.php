<?php declare(strict_types=1);

/**
 * Tarifdatei zwischen JSON und CSV umwandeln (und dabei prüfen).
 *
 *   php scripts/tarif-konvertieren.php tarife.json tarife.csv   # JSON → CSV (Excel, „;“, Dezimalkomma)
 *   php scripts/tarif-konvertieren.php tarife.csv tarife.json   # CSV → JSON
 *   php scripts/tarif-konvertieren.php tarife.csv               # nur prüfen
 */

use Nova\Versandkosten\Calculator\Tariff\TariffCsv;
use Nova\Versandkosten\Calculator\Tariff\TariffException;
use Nova\Versandkosten\Calculator\Tariff\TariffLoader;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nova\\Versandkosten\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

[$self, $in, $out] = $argv + [null, null, null];
if ($in === null) {
    fwrite(\STDERR, "Aufruf: php scripts/tarif-konvertieren.php EINGABE [AUSGABE]\n");
    exit(2);
}

try {
    $set = TariffLoader::parse((string) file_get_contents($in));
} catch (TariffException $e) {
    fwrite(\STDERR, "Tarifdatei ungültig:\n- " . implode("\n- ", $e->errors) . "\n");
    exit(1);
}

printf("OK: %s – %d Produkte, Optionen: %s\n", $set->name, \count($set->products), implode(', ', $set->options()));
if ($out === null) {
    exit(0);
}

if (str_ends_with(strtolower($out), '.csv')) {
    file_put_contents($out, "\xEF\xBB\xBF" . TariffCsv::fromArray($set->data));
} else {
    file_put_contents($out, json_encode($set->data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION) . "\n");
}
echo "geschrieben: $out\n";
