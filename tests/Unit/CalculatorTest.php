<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\ChannelRules;
use Nova\Versandkosten\Calculator\Countries;
use Nova\Versandkosten\Calculator\Destination;
use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Calculator\Money;
use Nova\Versandkosten\Calculator\PackageBuilder;
use Nova\Versandkosten\Calculator\PriceCheck;
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

    public function testShippingPropertyWithoutSmallValueBlocksLetterAndSmallParcel(): void
    {
        $rules = new ChannelRules(smallNoDims: ChannelRules::SMALL_WARENPOST);
        // Leicht, ohne Maße, aber laut Versandart nur Paket (z. B. Hutablage)
        $shelf = $this->calc([new Item('h', 'Hutablage', 0.4, null, null, null, unitPriceGross: 35.95, smallAllowed: false)], 'DE', $rules);
        self::assertFalse($shelf->option('warenpost')?->available);
        self::assertFalse($shelf->option('kleinpaket')?->available);
        self::assertTrue($shelf->option('dpd')?->available);

        // Ein solcher Artikel sperrt die Kleinsendung für den ganzen Warenkorb
        $mixed = $this->calc([
            new Item('a', 'Dichtung', 0.05, null, null, null, unitPriceGross: 6.95),
            new Item('h', 'Hutablage', 0.4, null, null, null, unitPriceGross: 9.95, smallAllowed: false),
        ], 'DE', $rules);
        self::assertFalse($mixed->option('kleinpaket')?->available);

        $ok = $this->calc([new Item('a', 'Dichtung', 0.05, null, null, null, unitPriceGross: 6.95)], 'DE', $rules);
        self::assertTrue($ok->option('warenpost')?->available);
    }

    public function testCarrierFromShippingPropertyRestrictsParcelCarriers(): void
    {
        self::assertSame(['dhl'], Item::carriersFromValues(['dhl sperrgut', 'kurier']));
        self::assertSame(['dpd', 'dhl'], Item::carriersFromValues(['dpd paket', 'dhl paket', 'warenpost']));
        self::assertNull(Item::carriersFromValues(['kurier', 'warenpost']));
        self::assertNull(Item::carriersFromValues([]));

        // Tür: laut Versandart nur DHL Sperrgut → kein DPD
        $door = $this->calc([new Item('t', 'Tür', 12.0, 110.0, 80.0, 15.0, Item::BULKY, unitPriceGross: 319.5, carriers: ['dhl'])]);
        self::assertFalse($door->option('dpd')?->available);
        self::assertTrue($door->option('dhl')?->available);

        // Gemischt: Artikel ohne Einschränkung ändert nichts, widersprüchliche Dienste → kein Paket
        $mixed = $this->calc([
            new Item('t', 'Tür', 12.0, 110.0, 80.0, 15.0, Item::BULKY, unitPriceGross: 319.5, carriers: ['dhl']),
            new Item('a', 'Dichtung', 0.3, 10.0, 10.0, 5.0, unitPriceGross: 6.95),
        ]);
        self::assertFalse($mixed->option('dpd')?->available);
        self::assertTrue($mixed->option('dhl')?->available);

        $none = $this->calc([
            new Item('t', 'Tür', 12.0, 110.0, 80.0, 15.0, Item::BULKY, unitPriceGross: 319.5, carriers: ['dhl']),
            new Item('d', 'DPD-Teil', 2.0, 30.0, 20.0, 10.0, unitPriceGross: 20.0, carriers: ['dpd']),
        ]);
        self::assertFalse($none->option('dpd')?->available);
        self::assertFalse($none->option('dhl')?->available);
    }

    public function testSmallShipmentWithoutDimensions(): void
    {
        $small = [new Item('a', 'Dichtung', 0.3, null, null, null, unitPriceGross: 6.95)];

        // Aus (Standard des Rechenkerns): Standardmaße 30 × 20 × 15 passen nie in Warenpost/Kleinpaket
        $off = $this->calc($small);
        self::assertFalse($off->option('warenpost')?->available);
        self::assertFalse($off->option('kleinpaket')?->available);
        self::assertTrue($off->option('dpd')?->available);

        $warenpost = $this->calc($small, 'DE', new ChannelRules(smallNoDims: ChannelRules::SMALL_WARENPOST));
        self::assertTrue($warenpost->packaging->smallNoDims);
        self::assertSame(500, $warenpost->option('warenpost')?->saleCents);
        self::assertTrue($warenpost->option('kleinpaket')?->available);
        self::assertSame(true, $warenpost->option('warenpost')?->meta['smallNoDims'] ?? null);
        self::assertTrue($warenpost->dimsMissing(), '„Maße fehlen“ bleibt gesetzt');
        // Paketmaß der Bestellung bleibt das Standardmaß
        self::assertSame(30.0, $warenpost->option('warenpost')?->packages[0]['lengthCm']);

        $kleinpaket = $this->calc($small, 'DE', new ChannelRules(smallNoDims: ChannelRules::SMALL_KLEINPAKET));
        self::assertFalse($kleinpaket->option('warenpost')?->available);
        self::assertTrue($kleinpaket->option('kleinpaket')?->available);

        // Gesamtgewicht über der Grenze
        $heavy = $this->calc([new Item('a', 'Teil', 0.6, null, null, null, quantity: 2, unitPriceGross: 5)], 'DE', new ChannelRules(smallNoDims: ChannelRules::SMALL_WARENPOST));
        self::assertFalse($heavy->packaging->smallNoDims);
        self::assertFalse($heavy->option('warenpost')?->available);

        // Ein Artikel mit Maßen: exakt prüfen wie bisher
        $mixed = $this->calc([...$small, new Item('b', 'Karton', 0.2, 30, 20, 15, unitPriceGross: 5)], 'DE', new ChannelRules(smallNoDims: ChannelRules::SMALL_WARENPOST));
        self::assertFalse($mixed->packaging->smallNoDims);
        self::assertFalse($mixed->option('warenpost')?->available);

        // Nur Einordnung „Paket“; Sperrgut zählt nie als Kleinsendung
        $bulky = $this->calc([new Item('c', 'Rohr', 0.3, null, null, null, Item::BULKY, unitPriceGross: 5)], 'DE', new ChannelRules(smallNoDims: ChannelRules::SMALL_WARENPOST));
        self::assertFalse($bulky->packaging->smallNoDims);

        // Warenwertgrenze gilt weiter
        $expensive = $this->calc([new Item('a', 'Teil', 0.3, null, null, null, unitPriceGross: 80)], 'DE', new ChannelRules(smallNoDims: ChannelRules::SMALL_WARENPOST));
        self::assertFalse($expensive->option('warenpost')?->available);
        self::assertFalse($expensive->option('kleinpaket')?->available);
    }

    public function testFixedPriceWeightLimit(): void
    {
        $heavy = [new Item('a', 'Block', 20, 60, 40, 40, unitPriceGross: 50)];
        $light = [new Item('b', 'Teil', 5, 40, 30, 20, unitPriceGross: 50)];

        // ohne Grenze: Festpreis
        self::assertSame(750, $this->calc($heavy)->option('dpd')?->saleCents);

        // DPD 20 kg (Platzhaltertarif): 4,50 + Maut 0,30 + Sicherheit 0,10 = 4,90 netto → 5,83 brutto
        $limited = new ChannelRules(fixedPriceMaxKg: ['dpd' => 10.0]);
        $r = $this->calc($heavy, 'DE', $limited);
        self::assertSame(583, $r->option('dpd')?->saleCents);
        self::assertSame(true, $r->option('dpd')?->meta['fixedOverLimit'] ?? null);
        self::assertSame(750, $this->calc($light, 'DE', $limited)->option('dpd')?->saleCents);
        self::assertSame(1050, $r->option('dhl')?->saleCents, 'Grenze gilt nur für DPD');

        $surcharge = new ChannelRules(fixedPriceMaxKg: ['dpd' => 10.0], fixedOverMode: ChannelRules::FIXED_OVER_SURCHARGE, fixedOverSurcharge: 3.0);
        self::assertSame(883, $this->calc($heavy, 'DE', $surcharge)->option('dpd')?->saleCents);

        // Ausland unverändert (kein Festpreis)
        self::assertSame(
            $this->calc($heavy, 'AT')->option('dpd')?->saleCents,
            $this->calc($heavy, 'AT', $limited)->option('dpd')?->saleCents,
        );

        // Preisprüfung rechnet genauso
        $rows = PriceCheck::run(self::tariffs(), $limited, new \DateTimeImmutable('2027-03-10 10:00'));
        $byLabel = array_column($rows, null, 'label');
        self::assertFalse($byLabel['DPD 5 kg']['overLimit']);
        self::assertTrue($byLabel['DPD 20 kg']['overLimit']);
        self::assertSame(10.0, $byLabel['DPD 20 kg']['fixedMaxKg']);
        self::assertSame($byLabel['DPD 20 kg']['purchaseNet'], round($byLabel['DPD 20 kg']['saleNet'], 2));
    }
}
