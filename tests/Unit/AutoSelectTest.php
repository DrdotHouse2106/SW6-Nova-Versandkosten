<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Checkout\AutoSelectDecision as D;
use PHPUnit\Framework\TestCase;

final class AutoSelectTest extends TestCase
{
    private const SMALL = [
        'warenpost' => ['id' => 'wp', 'price' => 5.0],
        'kleinpaket' => ['id' => 'kp', 'price' => 7.73],
        'dpd' => ['id' => 'dpd', 'price' => 7.5],
        'dhl' => ['id' => 'dhl', 'price' => 10.73],
    ];

    public function testDefaultDpdSwitchesToCheapest(): void
    {
        self::assertSame('wp', D::choose('dpd', 'dpd', false, false, self::SMALL));
    }

    public function testManualChoiceStays(): void
    {
        self::assertNull(D::choose('dhl', 'dhl', false, true, self::SMALL));
    }

    public function testAlreadyCheapestStays(): void
    {
        self::assertNull(D::choose('warenpost', 'wp', false, false, self::SMALL));
    }

    public function testSamePriceKeepsCurrent(): void
    {
        $c = ['dpd' => ['id' => 'dpd', 'price' => 7.5], 'kleinpaket' => ['id' => 'kp', 'price' => 7.5]];
        self::assertNull(D::choose('dpd', 'dpd', false, false, $c));
    }

    public function testBlockedManualSwitchesToCheapest(): void
    {
        $c = ['dpd' => ['id' => 'dpd', 'price' => 8.07], 'dhl' => ['id' => 'dhl', 'price' => 11.92]];
        self::assertSame('dpd', D::choose('warenpost', 'wp', true, true, $c));
    }

    public function testBlockedWithoutParcelGoesToRequest(): void
    {
        $c = ['anfrage' => ['id' => 'req', 'price' => 0.0]];
        self::assertSame('req', D::choose('dpd', 'dpd', true, false, $c));
    }

    public function testRequestNotChosenWhenParcelPossible(): void
    {
        $c = self::SMALL + ['anfrage' => ['id' => 'req', 'price' => 0.0]];
        self::assertSame('wp', D::choose('dhl', 'dhl', true, false, $c));
    }

    public function testNotChosenPickupOrForeignSwitchesToCheapestParcel(): void
    {
        // Abholung kostet 0 € – ohne eigene Wahl trotzdem das günstigste Paket vorwählen
        self::assertSame('wp', D::choose('abholung', 'pickup', false, false, self::SMALL));
        self::assertSame('wp', D::choose(null, 'abholung-treffen', false, false, self::SMALL));
        self::assertSame('wp', D::choose(null, 'alt-standard', true, false, self::SMALL));
    }

    public function testManuallyChosenSpecialMethodsStay(): void
    {
        self::assertNull(D::choose('abholung', 'pickup', false, true, self::SMALL));
        self::assertNull(D::choose(null, 'abholung-treffen', false, true, self::SMALL));
        self::assertNull(D::choose('beipack', 'bp', false, true, self::SMALL));
        self::assertNull(D::choose('anfrage', 'req', false, true, self::SMALL));
    }

    public function testNothingPossible(): void
    {
        self::assertNull(D::choose('dpd', 'dpd', true, false, []));
    }
}
