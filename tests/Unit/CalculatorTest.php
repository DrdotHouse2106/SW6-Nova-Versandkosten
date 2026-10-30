<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\ChannelRules;
use Nova\Versandkosten\Calculator\Countries;
use Nova\Versandkosten\Calculator\Destination;
use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Calculator\Money;
use Nova\Versandkosten\Calculator\PackageBuilder;
use Nova\Versandkosten\Calculator\ShippingCalculator;
use Nova\Versandkosten\Calculator\ShippingResult;
use Nova\Versandkosten\Calculator\Tariff\TariffLoader;
use Nova\Versandkosten\Calculator\Tariff\TariffSet;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    private static ?TariffSet $tariffs = null;

    private static function tariffs(): TariffSet
    {
        return self::$tariffs ??= TariffLoader::parse((string) file_get_contents(__DIR__ . '/../../examples/tarife-beispiel.json'));
    }

    /** @param list<Item> $items */
    private function calc(array $items, string $country = 'DE', ?ChannelRules $rules = null, string $postcode = '', string $date = '2027-03-10 10:00'): ShippingResult
    {
        return (new ShippingCalculator())->calculate($items, new Destination($country, $postcode), $rules ?? new ChannelRules(), self::tariffs(), new \DateTimeImmutable($date));
    }

    public function testMissingDimensionsUseDefaultsAndFlag(): void
    {
        $result = $this->calc([new Item('a', 'Teil', 2.0, null, null, null)]);
        self::assertTrue($result->dimsMissing());
        self::assertSame([30.0, 20.0, 15.0], $result->packaging->packages[0]->dims);

        $bulky = $this->calc([new Item('b', 'Sperrig', null, null, null, null, Item::BULKY)]);
        self::assertSame([130.0, 40.0, 40.0], $bulky->packaging->packages[0]->dims);
        self::assertSame(1.0, $bulky->packaging->packages[0]->weightKg);
    }

    public function testSmallPartsJoinBulkyPackageAndBoxGrows(): void
    {
        $packaging = PackageBuilder::build([
            new Item('a', 'Rohr', 5, 150, 10, 10, Item::BULKY),
            new Item('b', 'Karton', 3, 40, 30, 20),
        ], new ChannelRules());
        self::assertCount(1, $packaging->packages);
        self::assertSame([150.0, 30.0, 20.0], $packaging->packages[0]->dims);
        self::assertTrue($packaging->packages[0]->bulky);
        self::assertEqualsWithDelta(8.0, $packaging->packages[0]->weightKg, 1e-9);
    }

    public function testHeavyUnitsAreIndivisible(): void
    {
        $packaging = PackageBuilder::build([new Item('a', 'Block', 13, 30, 30, 30, quantity: 3)], new ChannelRules());
        self::assertCount(3, $packaging->packages);

        $single = PackageBuilder::build([new Item('a', 'Amboss', 28, 30, 30, 30)], new ChannelRules());
        self::assertCount(1, $single->packages);
        self::assertSame(28.0, $single->packages[0]->weightKg);
    }

    public function testPickupOnlyBeatsFreight(): void
    {
        $packaging = PackageBuilder::build([
            new Item('a', 'Karosserie', 100, 300, 150, 100, Item::FREIGHT),
            new Item('b', 'Motor', 150, 80, 60, 60, Item::PICKUP_ONLY),
        ], new ChannelRules());
        self::assertSame(PackageBuilder::MODE_PICKUP, $packaging->mode);
    }

    public function testDpdOversizeBoundaryAt175(): void
    {
        $r = $this->calc([new Item('a', 'Rohr', 5, 175, 10, 10, Item::BULKY)]);
        $dpd = $r->option('dpd');
        self::assertNotNull($dpd);
        self::assertSame('Übergröße', $dpd->packages[0]['tier']);

        $r = $this->calc([new Item('a', 'Rohr', 5, 174, 10, 10)]);
        self::assertSame('Übermaß (NC)', $r->option('dpd')?->packages[0]['tier']);
    }

    public function testIslandSurchargeOnlyChangesPurchase(): void
    {
        $normal = $this->calc([new Item('a', 'Teil', 2, 30, 20, 15)], 'DE', null, '10115');
        $island = $this->calc([new Item('a', 'Teil', 2, 30, 20, 15)], 'DE', null, '25846');
        self::assertSame(750, $island->option('dpd')?->saleCents);
        self::assertSame(Money::fromEuro(12), ($island->option('dpd')?->purchaseNet ?? 0) - ($normal->option('dpd')?->purchaseNet ?? 0));
    }

    public function testPartiallyFreeCartIsNotFree(): void
    {
        $r = $this->calc([
            new Item('a', 'Eigenes Buch', 0.4, 25, 18, 3, unitPriceGross: 10, free: true),
            new Item('b', 'Fremdes Buch', 0.3, 25, 18, 2, unitPriceGross: 10),
        ]);
        self::assertFalse($r->free);
        self::assertSame(750, $r->option('dpd')?->saleCents);
    }

    public function testChannelSwitches(): void
    {
        $items = [new Item('a', 'Teil', 2, 30, 20, 15, unitPriceGross: 60)];

        $r = $this->calc($items, 'CH', new ChannelRules(offerDpdOutsideEu: false));
        self::assertFalse($r->option('dpd')?->available);
        self::assertTrue($r->option('dhl')?->available);
        self::assertFalse($r->option('dhl')?->vatIncluded);

        $r = $this->calc($items, 'AT', new ChannelRules(allowedCountries: ['DE']));
        self::assertFalse($r->option('dpd')?->available);
        self::assertTrue($r->option('abholung')?->available);

        // Ohne Festpreis: Einkauf brutto (DPD 2 kg: 3,00 + 0,30 + 0,10 = 3,40 → 4,05)
        $r = $this->calc($items, 'DE', new ChannelRules(fixedPrices: ['dpd' => null]));
        self::assertSame(405, $r->option('dpd')?->saleCents);

        // Sperrgut-Aufschlag nicht weitergeben
        $r = $this->calc([new Item('a', 'Rohr', 5, 130, 10, 10, Item::BULKY)], 'DE', new ChannelRules(passOversize: false));
        self::assertSame(750, $r->option('dpd')?->saleCents);

        // Peak nicht weitergeben, Hinweis entfällt
        $r = $this->calc($items, 'DE', new ChannelRules(passPeak: false), '', '2027-11-10 10:00');
        self::assertSame(1050, $r->option('dhl')?->saleCents);
        self::assertFalse($r->option('dhl')?->hasHint('peak'));

        $r = $this->calc([], 'DE', new ChannelRules(pickupEnabled: false));
        self::assertFalse($r->option('abholung')?->available);
    }

    public function testDeliveryEstimateFromTransitTable(): void
    {
        $rules = new ChannelRules(transitTable: ChannelRules::parseTransitTable("dpd;DE;1-2\n*;EU;3-5\n# Kommentar\n*;*;5"));
        self::assertSame([1, 2], $rules->transitDays('dpd', 'DE'));
        self::assertSame([3, 5], $rules->transitDays('dhl', 'AT'));
        self::assertSame([5, 5], $rules->transitDays('dhl', 'US'));
        self::assertSame([2, 3], (new ChannelRules())->transitDays('dpd', 'AT'));
        self::assertSame([4, 10], (new ChannelRules())->transitDays('warenpost', 'FR'));
        self::assertTrue(Countries::matches('NACHBARN', 'PL'));
        self::assertFalse(Countries::isEu('CH'));
    }
}
