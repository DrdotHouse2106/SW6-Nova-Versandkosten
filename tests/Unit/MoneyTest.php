<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testGrossRoundsHalfUpAfterVat(): void
    {
        // 6,50 × 1,19 = 7,735 → 7,74 (kaufmännisch, ganzzahlig gerechnet)
        self::assertSame(774, Money::grossCents(Money::fromEuro(6.50), 19));
        // 4,50 × 1,19 = 5,355 → 5,36
        self::assertSame(536, Money::grossCents(Money::fromEuro(4.50), 19));
        self::assertSame(2142, Money::grossCents(Money::fromEuro(18.00), 19));
        self::assertSame(24, Money::grossCents(Money::fromEuro(0.20), 19));
    }

    public function testPercentKeepsSubCentPrecision(): void
    {
        // 19 % von 9,45 = 1,7955 €
        self::assertSame(17955, Money::percentOf(Money::fromEuro(9.45), 19));
        // netto 31,2955 → 31,30 (ohne MwSt.)
        self::assertSame(3130, Money::toCents(Money::fromEuro(9.50) + Money::fromEuro(20) + 17955));
    }

    public function testFromEuroAcceptsGermanDecimal(): void
    {
        self::assertSame(45000, Money::fromEuro('4,50'));
        self::assertSame('10,50', Money::formatCents(1050));
        self::assertSame(-2, Money::divRound(-15, 10));
    }
}
