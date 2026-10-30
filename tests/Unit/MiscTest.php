<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\FreightQuote;
use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Calculator\ProductLabel;
use Nova\Versandkosten\Service\CargoboardQuoteProvider;
use Nova\Versandkosten\Service\MethodMap;
use PHPUnit\Framework\TestCase;

final class MiscTest extends TestCase
{
    public function testCargoboardResponse(): void
    {
        $quote = CargoboardQuoteProvider::parseResponse([
            'data' => ['id' => 'q-1', 'price' => ['amount' => 312.5, 'currency' => 'EUR'], 'runtime' => ['daysMin' => 2, 'daysMax' => 3]],
        ]);
        self::assertSame(FreightQuote::OK, $quote->status);
        self::assertSame(312.5, $quote->netPrice);
        self::assertSame(2, $quote->daysMin);
        self::assertSame('q-1', $quote->quotationId);

        self::assertSame(FreightQuote::ERROR, CargoboardQuoteProvider::parseResponse(['data' => []])->status);
    }

    public function testProductLabel(): void
    {
        self::assertSame('paket', ProductLabel::label(Item::PARCEL, [40, 30, 20]));
        self::assertSame('paket', ProductLabel::label(Item::PARCEL, null));
        self::assertSame('sperrgut', ProductLabel::label(Item::PARCEL, [130, 40, 40]));
        self::assertSame('sperrgut', ProductLabel::label(Item::BULKY, [50, 20, 20]));
        self::assertSame('spedition', ProductLabel::label(Item::FREIGHT, null));
    }

    public function testMethodMap(): void
    {
        self::assertSame('nova_versandkosten_dhl', MethodMap::technicalName('dhl'));
        self::assertSame('dhl', MethodMap::optionFor('nova_versandkosten_dhl'));
        self::assertNull(MethodMap::optionFor('nova_versandkosten_xyz'));
        self::assertNull(MethodMap::optionFor('shipping_standard'));
    }
}
