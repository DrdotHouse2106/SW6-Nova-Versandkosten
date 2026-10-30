<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\CaseRunner;
use Nova\Versandkosten\Calculator\Tariff\TariffLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Testfälle der Rechenregel (T1–T17 + Peak/Energie/Grenzfälle) mit Platzhaltertarifen –
 * einmal aus der JSON-, einmal aus der CSV-Fassung der Beispieltarife (beide müssen gleich rechnen).
 */
final class CasesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function tariffFiles(): iterable
    {
        yield 'JSON' => [__DIR__ . '/../../examples/tarife-beispiel.json'];
        yield 'CSV' => [__DIR__ . '/../../examples/tarife-beispiel.csv'];
    }

    #[DataProvider('tariffFiles')]
    public function testAllCasesMatch(string $file): void
    {
        $tariffs = TariffLoader::parse((string) file_get_contents($file));
        $spec = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/testfaelle-beispiel.json'), true, 64, \JSON_THROW_ON_ERROR);
        $rows = CaseRunner::run($tariffs, $spec);

        self::assertGreaterThan(80, \count($rows));
        $failures = array_filter($rows, static fn (array $r) => !$r['ok']);
        self::assertSame([], array_values(array_map(
            static fn (array $r) => \sprintf('%s %s: Soll %s, Ist %s', $r['case'], $r['check'], $r['expected'], $r['actual']),
            $failures
        )));
    }
}
