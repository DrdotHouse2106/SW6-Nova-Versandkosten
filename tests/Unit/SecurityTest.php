<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\Beipack;
use Nova\Versandkosten\Calculator\ChannelRules;
use Nova\Versandkosten\Calculator\CsvCell;
use Nova\Versandkosten\Calculator\Destination;
use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Calculator\OrderCostSplit;
use Nova\Versandkosten\Calculator\RequestOrder;
use Nova\Versandkosten\Calculator\ShippingCalculator;
use Nova\Versandkosten\Calculator\ShippingResult;
use Nova\Versandkosten\Calculator\Tariff\TariffLoader;
use PHPUnit\Framework\TestCase;

/**
 * Sicherheits-Release 1.4.0: keine Einkaufsdaten an Kunden, Beipack-/Storno-Regeln, CSV-Export.
 */
final class SecurityTest extends TestCase
{
    private function calc(): ShippingResult
    {
        $tariffs = TariffLoader::parse((string) file_get_contents(__DIR__ . '/../../examples/tarife-beispiel.json'));

        return (new ShippingCalculator())->calculate(
            [new Item('a', 'Teil', 4.0, 40, 30, 20, Item::PARCEL, Item::PACK_SINGLE, 2, 30.0)],
            new Destination('DE', '10115'),
            new ChannelRules(),
            $tariffs,
            new \DateTimeImmutable('2027-03-10 10:00')
        );
    }

    /**
     * Rekursiv alle Schlüssel eines Arrays.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function keys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            if (\is_array($value)) {
                $keys = [...$keys, ...self::keys($value)];
            }
        }

        return $keys;
    }

    public function testPublicCartDataHasNoPurchaseOrTariffDetails(): void
    {
        $result = $this->calc();
        $internal = $result->toArray();
        $public = $result->toPublicArray();

        // Intern ist alles da …
        self::assertContains('purchaseNet', self::keys($internal));
        self::assertContains('lines', self::keys($internal));

        // … öffentlich nichts davon
        $keys = self::keys($public);
        foreach (['purchaseNet', 'lines', 'productId', 'productName', 'packages', 'reason', 'goodsValue', 'transitMin'] as $forbidden) {
            self::assertNotContains($forbidden, $keys, $forbidden . ' darf nicht in der Warenkorb-Erweiterung stehen');
        }

        // Was die Leser brauchen (AutoSelect, Validator, OptionPresenter, Seiten) bleibt erhalten
        self::assertArrayHasKey('mode', $public);
        foreach ($public['options'] as $key => $option) {
            self::assertSame($key, $option['key']);
            foreach (['available', 'sale', 'vatIncluded', 'hints', 'packageCount', 'deliveryFrom', 'deliveryTo', 'recommended', 'carrierName', 'reasonCode', 'meta'] as $needed) {
                self::assertArrayHasKey($needed, $option);
            }
            self::assertSame($internal['options'][$key]['sale'], $option['sale']);
            self::assertSame($internal['options'][$key]['available'], $option['available']);
        }
    }

    public function testPublicMetaIsWhitelisted(): void
    {
        $option = new OptionResult(OptionResult::BEIPACK);
        $option->meta = ['basedOn' => 'dpd', 'combinedSale' => 7.5, 'paid' => 5.0, 'orderNumber' => '10001', 'orderId' => 'x', 'fixedOverLimit' => true];

        self::assertSame(['basedOn' => 'dpd', 'combinedSale' => 7.5, 'paid' => 5.0, 'orderNumber' => '10001'], $option->toPublicArray()['meta']);
    }

    public function testOrderCostSplitMovesPurchaseOut(): void
    {
        $fields = [
            'nova_versandkosten_option' => 'spedition',
            'nova_versandkosten_product' => 'Stückgut',
            'nova_versandkosten_purchase_net' => 123.45,
            'nova_versandkosten_packages' => [
                ['weightKg' => 80, 'lengthCm' => 120, 'widthCm' => 80, 'heightCm' => 60, 'bulky' => false, 'tier' => null, 'purchaseNet' => 123.45, 'sale' => 149.0],
            ],
            'nova_versandkosten_details' => ['mode' => 'freight', 'freightQuotationId' => 'q-1'],
            'nova_versandkosten_dims_missing' => true,
            'other_plugin' => 'bleibt',
        ];

        [$clean, $cost, $changed] = OrderCostSplit::split($fields);

        self::assertTrue($changed);
        self::assertArrayNotHasKey('nova_versandkosten_purchase_net', $clean);
        self::assertArrayNotHasKey('purchaseNet', $clean['nova_versandkosten_packages'][0]);
        self::assertSame(80, $clean['nova_versandkosten_packages'][0]['weightKg']);
        self::assertSame(149.0, $clean['nova_versandkosten_packages'][0]['sale']);
        self::assertArrayNotHasKey('freightQuotationId', $clean['nova_versandkosten_details']);
        self::assertTrue($clean['nova_versandkosten_dims_missing']);
        self::assertSame('bleibt', $clean['other_plugin']);

        self::assertNotNull($cost);
        self::assertSame(123.45, $cost['purchaseNet']);
        self::assertSame('q-1', $cost['data']['freightQuotationId']);
        self::assertSame(123.45, $cost['data']['packages'][0]['purchaseNet']);

        // Zweiter Lauf: nichts mehr zu tun
        [$again, $none, $changedAgain] = OrderCostSplit::split($clean);
        self::assertFalse($changedAgain);
        self::assertNull($none);
        self::assertSame($clean, $again);
    }

    public function testOrderCostSplitWithoutPluginData(): void
    {
        [$clean, $cost, $changed] = OrderCostSplit::split(['foo' => 1]);
        self::assertSame(['foo' => 1], $clean);
        self::assertNull($cost);
        self::assertFalse($changed);
    }

    public function testBeipackRequiresSecuredPayment(): void
    {
        $now = new \DateTimeImmutable('2027-03-20 12:00');
        $order = [
            'orderState' => 'open',
            'deliveryState' => 'open',
            'orderDate' => new \DateTimeImmutable('2027-03-15 09:00'),
            'salesChannelId' => 'abc',
            'country' => 'DE',
            'isBeipack' => false,
            'option' => 'dpd',
            'paymentState' => 'open',
            'paymentHandler' => 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment',
        ];

        // Vorkasse offen: nein (außer per Einstellung)
        self::assertNotNull(Beipack::ineligibleReason($order, 'abc', 'DE', $now, 14));
        self::assertNull(Beipack::ineligibleReason($order, 'abc', 'DE', $now, 14, true));
        // bezahlt / autorisiert: ja
        self::assertNull(Beipack::ineligibleReason(['paymentState' => 'paid'] + $order, 'abc', 'DE', $now, 14));
        self::assertNull(Beipack::ineligibleReason(['paymentState' => 'authorized'] + $order, 'abc', 'DE', $now, 14));
        // Rechnung offen: ja (wird nach dem Versand bezahlt), Rechnung storniert: nein
        $invoice = ['paymentHandler' => '\\Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\InvoicePayment'] + $order;
        self::assertNull(Beipack::ineligibleReason($invoice, 'abc', 'DE', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason(['paymentState' => 'cancelled'] + $invoice, 'abc', 'DE', $now, 14));
        // Tour und Abholung: nie
        self::assertNotNull(Beipack::ineligibleReason(['option' => OptionResult::TOUR, 'paymentState' => 'paid'] + $order, 'abc', 'DE', $now, 14));
        self::assertNotNull(Beipack::ineligibleReason(['option' => OptionResult::PICKUP, 'paymentState' => 'paid'] + $order, 'abc', 'DE', $now, 14));
    }

    public function testAutoCancelOnlyPricedUnshippedFromPriceDate(): void
    {
        $now = new \DateTimeImmutable('2027-03-30 12:00');
        $priced = new \DateTimeImmutable('2027-03-10 12:00');
        $recent = new \DateTimeImmutable('2027-03-25 12:00');

        self::assertTrue(RequestOrder::isDueForCancellation($priced, $now, 14, 'open', 'open', RequestOrder::STATUS_PRICED, 'open'));
        // Preis erst vor 5 Tagen eingetragen: Frist läuft noch
        self::assertFalse(RequestOrder::isDueForCancellation($recent, $now, 14, 'open', 'open', RequestOrder::STATUS_PRICED, 'open'));
        // Nie bepreist: nie stornieren
        self::assertFalse(RequestOrder::isDueForCancellation($priced, $now, 14, 'open', 'open', RequestOrder::STATUS_OPEN, 'open'));
        // Bereits versendet (z. B. Rechnung): nicht stornieren
        self::assertFalse(RequestOrder::isDueForCancellation($priced, $now, 14, 'open', 'open', RequestOrder::STATUS_PRICED, 'shipped'));
        self::assertFalse(RequestOrder::isDueForCancellation($priced, $now, 14, 'open', 'open', RequestOrder::STATUS_PRICED, 'shipped_partially'));
    }

    public function testCsvCellsAreDefused(): void
    {
        self::assertSame("'=HYPERLINK(\"https://example.com\")", CsvCell::safe('=HYPERLINK("https://example.com")'));
        self::assertSame("'+49 30 123", CsvCell::safe('+49 30 123'));
        self::assertSame("'-1", CsvCell::safe('-1'));
        self::assertSame("'@SUM(A1)", CsvCell::safe('@SUM(A1)'));
        self::assertSame("'  =1+1", CsvCell::safe('  =1+1'));
        self::assertSame("'\t=1", CsvCell::safe("\t=1"));
        self::assertSame("'＝1", CsvCell::safe('＝1'));
        self::assertSame('Demo Kunde', CsvCell::safe('Demo Kunde'));
        self::assertSame('K-1001', CsvCell::safe('K-1001'));
        self::assertSame('', CsvCell::safe(''));
    }
}
