<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\Beipack;
use Nova\Versandkosten\Calculator\ChannelRules;
use Nova\Versandkosten\Calculator\Destination;
use Nova\Versandkosten\Calculator\FreightQuote;
use Nova\Versandkosten\Calculator\FreightQuoteProvider;
use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Calculator\PackageBuilder;
use Nova\Versandkosten\Calculator\ProductLabel;
use Nova\Versandkosten\Calculator\RequestOrder;
use Nova\Versandkosten\Calculator\ShippingCalculator;
use Nova\Versandkosten\Calculator\ShippingResult;
use Nova\Versandkosten\Calculator\Tariff\TariffLoader;
use Nova\Versandkosten\Calculator\Tariff\TariffSet;
use Nova\Versandkosten\Service\AttemptLimiter;
use Nova\Versandkosten\Energy\EnergyState;
use PHPUnit\Framework\TestCase;

/**
 * Beipacken (Differenzpreis, Prüfung), Versandkosten auf Anfrage (wann angeboten, Zahlarten,
 * automatische Stornierung), Versuchsbegrenzung, Energie-Zustand – ohne Shopware-Kernel.
 */
final class BeipackRequestTest extends TestCase
{
    private static ?TariffSet $tariffs = null;

    private static function tariffs(): TariffSet
    {
        return self::$tariffs ??= TariffLoader::parse((string) file_get_contents(__DIR__ . '/../../examples/tarife-beispiel.json'));
    }

    /** @param list<Item> $items */
    private function calc(array $items, string $country = 'DE', ?ChannelRules $rules = null, ?FreightQuoteProvider $freight = null): ShippingResult
    {
        return (new ShippingCalculator())->calculate($items, new Destination($country, '35428'), $rules ?? new ChannelRules(), self::tariffs(), new \DateTimeImmutable('2027-03-10 10:00'), $freight);
    }

    private static function small(string $id, float $price = 18.0): Item
    {
        return new Item($id, 'Kleinteil', 0.4, 20, 15, 4, unitPriceGross: $price);
    }

    public function testBeipackWarenpostBecomesKleinpaket(): void
    {
        // Alte Bestellung: Warenpost 5,00 bezahlt; zusammen über der Warenwertgrenze → Kleinpaket 7,50 → 2,50 nachzahlen
        $combined = $this->calc([self::small('alt'), self::small('neu')]);
        self::assertFalse($combined->option(OptionResult::WARENPOST)?->available);

        $quote = Beipack::quote($combined, OptionResult::WARENPOST, 500);
        self::assertTrue($quote->available);
        self::assertSame(OptionResult::BEIPACK, $quote->key);
        self::assertSame(OptionResult::KLEINPAKET, $quote->meta['basedOn']);
        self::assertSame(250, $quote->saleCents);
        self::assertSame(7.5, $quote->meta['combinedSale']);
        self::assertSame(5.0, $quote->meta['paid']);
    }

    public function testBeipackSameOptionCostsNothing(): void
    {
        // Alte Bestellung DPD 7,50; zusammen 2 kg, weiterhin ein DPD-Paket → 0,00
        $combined = $this->calc([new Item('alt', 'Teil', 1.0, 30, 20, 10, unitPriceGross: 40), new Item('neu', 'Teil', 1.0, 30, 20, 10, unitPriceGross: 40)]);
        $quote = Beipack::quote($combined, OptionResult::DPD, 750);
        self::assertSame(OptionResult::DPD, $quote->meta['basedOn']);
        self::assertSame(0, $quote->saleCents);

        // Nie negativ (alte Bestellung teurer bezahlt)
        self::assertSame(0, Beipack::quote($combined, OptionResult::DPD, 2000)->saleCents);
        self::assertSame(0, Beipack::difference(500, 900));
        self::assertSame(250, Beipack::difference(750, 500));
    }

    public function testBeipackUnknownOptionTakesCheapest(): void
    {
        $combined = $this->calc([self::small('alt', 5.0)]);
        $quote = Beipack::quote($combined, null, 0);
        self::assertSame(OptionResult::WARENPOST, $quote->meta['basedOn']);
        self::assertSame(500, $quote->saleCents);
    }

    public function testBeipackNothingFits(): void
    {
        $combined = $this->calc([new Item('k', 'Karosserie', 150, 420, 180, 140, Item::FREIGHT)]);
        $quote = Beipack::quote($combined, OptionResult::DPD, 750);
        self::assertFalse($quote->available);
    }

    public function testBeipackEligibility(): void
    {
        $now = new \DateTimeImmutable('2027-03-20 12:00');
        $order = [
            'orderState' => 'open',
            'deliveryState' => 'open',
            'orderDate' => new \DateTimeImmutable('2027-03-15 09:00'),
            'salesChannelId' => 'ABC',
            'country' => 'DE',
            'isBeipack' => false,
        ];
        self::assertNull(Beipack::ineligibleReason($order, 'abc', 'DE', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason(['deliveryState' => 'shipped'] + $order, 'abc', 'DE', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason(['deliveryState' => 'shipped_partially'] + $order, 'abc', 'DE', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason(['orderState' => 'cancelled'] + $order, 'abc', 'DE', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason($order, 'other', 'DE', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason($order, 'abc', 'AT', $now, 14));
        self::assertNull(Beipack::ineligibleReason($order, 'abc', '', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason(['isBeipack' => true] + $order, 'abc', 'DE', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason($order, 'abc', 'DE', $now, 3));
        self::assertNull(Beipack::ineligibleReason($order, 'abc', 'DE', $now, 0));
    }

    public function testBeipackComment(): void
    {
        self::assertSame('BEIPACK zu Bestellung 10023', Beipack::comment('10023', null));
        self::assertSame("BEIPACK zu Bestellung 10023\nBitte klingeln", Beipack::comment('10023', ' Bitte klingeln '));
        self::assertSame('BEIPACK zu Bestellung 10023', Beipack::comment('10023', 'BEIPACK zu Bestellung 10023'));
    }

    public function testRequestOptionTriggers(): void
    {
        $all = new ChannelRules(requestTriggers: [ChannelRules::REQUEST_FREIGHT, ChannelRules::REQUEST_NO_TARIFF, ChannelRules::REQUEST_ITEMS, ChannelRules::REQUEST_WITH_FREIGHT]);

        // Normaler Paket-Warenkorb: keine Anfrage
        $parcel = $this->calc([self::small('a')], 'DE', $all);
        self::assertFalse($parcel->option(OptionResult::REQUEST)?->available);

        // Spedition ohne Preis (kein Schlüssel) → Anfrage, ersetzt den Hinweis „auf Anfrage“
        $freight = $this->calc([new Item('k', 'Karosserie', 150, 420, 180, 140, Item::FREIGHT)], 'DE', $all);
        self::assertFalse($freight->option(OptionResult::FREIGHT)?->available);
        $request = $freight->option(OptionResult::REQUEST);
        self::assertTrue($request?->available);
        self::assertSame(0, $request->saleCents);
        self::assertSame(ChannelRules::REQUEST_FREIGHT, $request->meta['trigger']);
        self::assertFalse($request->recommended);

        // Spedition mit Preis → Anfrage zusätzlich (Alternative)
        $provider = new class implements FreightQuoteProvider {
            public function quote(Destination $destination, array $units, float $goodsValueGross): FreightQuote
            {
                return FreightQuote::ok(350.0, 2, 3);
            }
        };
        $priced = $this->calc([new Item('k', 'Karosserie', 150, 420, 180, 140, Item::FREIGHT)], 'DE', $all, $provider);
        self::assertTrue($priced->option(OptionResult::FREIGHT)?->available);
        self::assertTrue($priced->option(OptionResult::REQUEST)?->available);
        self::assertTrue($priced->option(OptionResult::REQUEST)?->hasHint('onRequestAlternative'));
        self::assertTrue($priced->option(OptionResult::FREIGHT)?->recommended);
        $noAlternative = new ChannelRules(requestTriggers: [ChannelRules::REQUEST_FREIGHT]);
        self::assertFalse($this->calc([new Item('k', 'Karosserie', 150, 420, 180, 140, Item::FREIGHT)], 'DE', $noAlternative, $provider)->option(OptionResult::REQUEST)?->available);

        // Land ohne Tarif
        $noTariff = $this->calc([self::small('a')], 'JP', $all);
        self::assertTrue($noTariff->option(OptionResult::REQUEST)?->available);
        self::assertSame(ChannelRules::REQUEST_NO_TARIFF, $noTariff->option(OptionResult::REQUEST)?->meta['trigger']);

        // Artikel „nur auf Anfrage“: ganze Sendung auf Anfrage, Abholung bleibt
        $items = $this->calc([self::small('a'), new Item('x', 'Sonderteil', 3, 50, 40, 30, Item::ON_REQUEST)], 'DE', $all);
        self::assertSame(PackageBuilder::MODE_REQUEST, $items->mode);
        self::assertFalse($items->option(OptionResult::DPD)?->available);
        self::assertFalse($items->option(OptionResult::FREIGHT)?->available);
        self::assertTrue($items->option(OptionResult::PICKUP)?->available);
        self::assertTrue($items->option(OptionResult::REQUEST)?->available);

        // Ohne Einstellung nie
        self::assertFalse($this->calc([self::small('a')], 'JP')->option(OptionResult::REQUEST)?->available);
        // „Immer“
        self::assertTrue($this->calc([self::small('a')], 'DE', new ChannelRules(requestTriggers: [ChannelRules::REQUEST_ALWAYS]))->option(OptionResult::REQUEST)?->available);
        // „Nur Abholung“ hat Vorrang vor „auf Anfrage“
        $pickup = PackageBuilder::build([new Item('x', 'A', 1, 10, 10, 10, Item::ON_REQUEST), new Item('y', 'B', 1, 10, 10, 10, Item::PICKUP_ONLY)], new ChannelRules());
        self::assertSame(PackageBuilder::MODE_PICKUP, $pickup->mode);
        self::assertSame('anfrage', ProductLabel::label(Item::ON_REQUEST, null));
    }

    public function testRequestPaymentMethods(): void
    {
        $methods = [
            'AA' => RequestOrder::PREPAYMENT_HANDLER,
            'bb' => 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\InvoicePayment',
            'cc' => 'Swag\\PayPal\\Checkout\\Payment\\PayPalPaymentHandler',
        ];
        self::assertSame(['aa'], RequestOrder::allowedPaymentMethods($methods, []));
        self::assertSame(['aa', 'bb'], RequestOrder::allowedPaymentMethods($methods, ['AA', 'bb']));
    }

    public function testRequestCancellation(): void
    {
        $now = new \DateTimeImmutable('2027-03-30 03:00');
        $old = new \DateTimeImmutable('2027-03-10 10:00');
        self::assertTrue(RequestOrder::isDueForCancellation($old, $now, 14, 'open', 'open'));
        self::assertFalse(RequestOrder::isDueForCancellation($old, $now, 0, 'open', 'open'));
        self::assertFalse(RequestOrder::isDueForCancellation($old, $now, 30, 'open', 'open'));
        self::assertFalse(RequestOrder::isDueForCancellation($old, $now, 14, 'open', 'paid'));
        self::assertFalse(RequestOrder::isDueForCancellation($old, $now, 14, 'open', 'paid_partially'));
        self::assertFalse(RequestOrder::isDueForCancellation($old, $now, 14, 'cancelled', 'open'));
        self::assertTrue(RequestOrder::isDueForCancellation($old, $now, 14, 'in_progress', null));
    }

    public function testRequestShippingInput(): void
    {
        self::assertSame(59.5, RequestOrder::shippingInput(59.5, 'gross', 19));
        self::assertSame(50.0, RequestOrder::shippingInput(59.5, 'net', 19));
        self::assertSame(45.0, RequestOrder::shippingInput(45, 'tax-free', 19));
        self::assertSame(0.0, RequestOrder::shippingInput(-3, 'gross', 19));
    }

    public function testAttemptLimiter(): void
    {
        $state = [];
        for ($i = 0; $i < AttemptLimiter::LIMIT; ++$i) {
            [$allowed, $state] = AttemptLimiter::register($state, 1000 + $i);
            self::assertTrue($allowed);
        }
        [$allowed, $state] = AttemptLimiter::register($state, 1010);
        self::assertFalse($allowed);
        // eine Stunde später wieder frei
        [$allowed] = AttemptLimiter::register($state, 1000 + AttemptLimiter::WINDOW_SECONDS + 10);
        self::assertTrue($allowed);
    }

    public function testEnergyStateValue(): void
    {
        $state = ['series' => [
            'dhl' => [['validFrom' => '2026-10-01', 'percent' => 4.0], ['validFrom' => '2026-11-01', 'percent' => 4.75]],
            'dpd' => [['validFrom' => '2026-11-01', 'percent' => 31.0]],
            'dpd_efs' => [['validFrom' => '2026-10-01', 'percent' => 3.2], ['validFrom' => '2026-11-01', 'percent' => 4.08]],
        ]];
        self::assertSame(4.0, EnergyState::valueAt($state, 'dhl', new \DateTimeImmutable('2026-10-31')));
        self::assertSame(4.75, EnergyState::valueAt($state, 'dhl', new \DateTimeImmutable('2026-11-01')));
        self::assertSame(35.08, EnergyState::valueAt($state, 'dpd', new \DateTimeImmutable('2026-11-02')));
        self::assertSame(31.0, EnergyState::valueAt($state, 'dpd', new \DateTimeImmutable('2026-11-02'), false));
        self::assertNull(EnergyState::valueAt($state, 'dpd', new \DateTimeImmutable('2026-10-15')));
        self::assertSame(['dhl' => 12.5, 'dpd' => 31.0], EnergyState::parseLines(['dhl=12,5', 'DPD: 31 %', 'unsinn']));
    }
}
