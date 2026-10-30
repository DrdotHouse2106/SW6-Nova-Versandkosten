<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Energy\EnergyEntry;
use Nova\Versandkosten\Energy\EnergyParseException;
use Nova\Versandkosten\Energy\EnergySurchargeParser as P;
use PHPUnit\Framework\TestCase;

/**
 * Parser gegen gekürzte Auszüge der echten Seiten (tests/Fixtures/energy, Stand 30.10.2026).
 */
final class EnergyParserTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/energy/' . $name);
    }

    public function testDhl(): void
    {
        $entries = P::parseDhl(self::fixture('dhl-2026-10.html'));
        $nov = EnergyEntry::valueAt($entries, new \DateTimeImmutable('2026-11-05'));
        self::assertNotNull($nov);
        self::assertSame('2026-11-01', $nov->validFrom);
        self::assertSame(4.75, $nov->percent);
        // Bestandteile aus den Tabellen „langfristig“/„kurzfristig“: 4,00 + 0,75
        self::assertSame(['langfristig' => 4.0, 'kurzfristig' => 0.75], $nov->components);

        $oct = EnergyEntry::valueAt($entries, new \DateTimeImmutable('2026-10-25'));
        self::assertSame('2026-10-21', $oct?->validFrom);
        self::assertSame(4.0, $oct?->percent);
        // Gesamtwert = langfristig + kurzfristig auch im Oktober (3,25 + 0,75)
        self::assertSame(4.0, ($oct?->components['langfristig'] ?? 0) + ($oct?->components['kurzfristig'] ?? 0));
        self::assertNull(EnergyEntry::valueAt($entries, new \DateTimeImmutable('2026-08-31')));
    }

    public function testDhlFallbackWithoutTotalTable(): void
    {
        $html = '<table><tr><th>Monat</th><th>Langfristige Komponente</th></tr><tr><td>November 2026</td><td>4,00 %</td></tr></table>'
            . '<table><tr><th>Monat</th><th>Kurzfristige Komponente ab 1. des Monats</th><th>Kurzfristige Komponente ab 11. des Monats</th></tr>'
            . '<tr><td>November 2026</td><td>0,75 %</td><td>1,00 %</td></tr></table>';
        $entries = P::parseDhl($html);
        self::assertSame(4.75, EnergyEntry::valueAt($entries, new \DateTimeImmutable('2026-11-02'))?->percent);
        self::assertSame(5.0, EnergyEntry::valueAt($entries, new \DateTimeImmutable('2026-11-12'))?->percent);
    }

    public function testDpd(): void
    {
        $entries = P::parseDpd(self::fixture('dpd-2026-10.html'));
        self::assertCount(12, $entries);
        $nov = EnergyEntry::valueAt($entries, new \DateTimeImmutable('2026-11-01'));
        self::assertSame(31.0, $nov?->percent);
        self::assertSame(['strasse' => 31.0, 'luft' => 40.0, 'weltweit' => 31.0], $nov?->components);
        self::assertSame('2025-12-01', $entries[0]->validFrom);
    }

    public function testDpdEmergency(): void
    {
        $entries = P::parseDpdEmergency(self::fixture('dpd-efs-2026-10.html'));
        self::assertCount(7, $entries);
        self::assertSame(4.08, EnergyEntry::valueAt($entries, new \DateTimeImmutable('2026-11-15'))?->percent);
        self::assertSame(3.2, EnergyEntry::valueAt($entries, new \DateTimeImmutable('2026-10-31'))?->percent);
    }

    public function testChangedPageThrows(): void
    {
        $this->expectException(EnergyParseException::class);
        P::parseDpd('<html><body><p>Wartungsarbeiten</p><table><tr><td>Preis</td><td>9 %</td></tr></table></body></html>');
    }

    public function testHelpers(): void
    {
        self::assertSame(31.0, P::percent('31,00%'));
        self::assertSame(4.75, P::percent("4,75\u{00A0}%"));
        self::assertNull(P::percent(''));
        self::assertNull(P::percent('ab 1.'));
        self::assertSame('2026-11-01', P::date('01.11.2026'));
        self::assertNull(P::date('31.02.2026'));
        self::assertSame([2026, 3], P::month('März 2026'));
        self::assertSame([2026, 11], P::month('Nov 2026'));
        self::assertTrue(EnergyEntry::isPlausible(35.08));
        self::assertFalse(EnergyEntry::isPlausible(75.0));
        self::assertFalse(EnergyEntry::isPlausible(-1.0));
    }
}
