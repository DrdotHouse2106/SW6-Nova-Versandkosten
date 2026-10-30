<?php declare(strict_types=1);

/**
 * Testfälle gegen eine Tarifdatei laufen lassen (ohne Shopware).
 *
 *   php scripts/testfaelle.php                                  # Beispieltarife + Beispielfälle
 *   NOVA_VK_TARIFE=/pfad/tarife.json NOVA_VK_FAELLE=/pfad/faelle.json php scripts/testfaelle.php
 *
 * NOVA_VK_TARIFE: Tarifdatei (JSON oder CSV), NOVA_VK_FAELLE: Testfälle (Format wie
 * tests/Fixtures/testfaelle-beispiel.json). Echte Tarife und Fälle gehören nicht ins Repo.
 * Ausgabe: Tabelle Soll/Ist (Markdown), Rückgabewert 1 bei Abweichungen.
 */

use Nova\Versandkosten\Calculator\CaseRunner;
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

$tariffFile = getenv('NOVA_VK_TARIFE') ?: __DIR__ . '/../examples/tarife-beispiel.json';
$casesFile = getenv('NOVA_VK_FAELLE') ?: __DIR__ . '/../tests/Fixtures/testfaelle-beispiel.json';

try {
    $tariffs = TariffLoader::parse((string) file_get_contents($tariffFile));
} catch (TariffException $e) {
    fwrite(\STDERR, "Tarifdatei ungültig:\n- " . implode("\n- ", $e->errors) . "\n");
    exit(2);
}
$spec = json_decode((string) file_get_contents($casesFile), true, 64, \JSON_THROW_ON_ERROR);

$rows = CaseRunner::run($tariffs, $spec);
$failed = 0;
echo "| Fall | Prüfung | Soll | Ist | |\n|---|---|---|---|---|\n";
foreach ($rows as $row) {
    $failed += $row['ok'] ? 0 : 1;
    printf("| %s | %s | %s | %s | %s |\n", $row['case'], $row['check'], $row['expected'], $row['actual'], $row['ok'] ? 'ok' : '**ABWEICHUNG**');
}
printf("\n%d Prüfungen, %d Abweichungen (Tarif: %s)\n", \count($rows), $failed, $tariffs->name);
exit($failed > 0 ? 1 : 0);
