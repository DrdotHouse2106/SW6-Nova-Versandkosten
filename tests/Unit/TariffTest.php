<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\Tariff\TariffCsv;
use Nova\Versandkosten\Calculator\Tariff\TariffException;
use Nova\Versandkosten\Calculator\Tariff\TariffLoader;
use Nova\Versandkosten\Calculator\Tariff\TariffSet;
use PHPUnit\Framework\TestCase;

final class TariffTest extends TestCase
{
    public function testValidationCollectsAllErrors(): void
    {
        try {
            TariffSet::fromArray([
                'format' => 'falsch',
                'version' => 1,
                'products' => [
                    ['id' => 'a', 'carrier' => 'dhl', 'option' => 'luftpost', 'zones' => [['countries' => ['DE'], 'steps' => [['maxKg' => 5, 'price' => 1], ['maxKg' => 3, 'price' => 2]]]]],
                    ['id' => 'b', 'carrier' => 'dpd', 'option' => 'dpd', 'zones' => [['countries' => ['Deutschland'], 'perPiece' => 'x']]],
                ],
            ]);
            self::fail('TariffException erwartet');
        } catch (TariffException $e) {
            $text = implode("\n", $e->errors);
            self::assertStringContainsString('„format“', $text);
            self::assertStringContainsString('„option“', $text);
            self::assertStringContainsString('aufsteigend', $text);
            self::assertStringContainsString('Ländercode', $text);
            self::assertStringContainsString('perPiece: Zahl erwartet', $text);
        }
    }

    public function testInvalidJsonAndEmptyFile(): void
    {
        $this->expectException(TariffException::class);
        TariffLoader::parse('{ kaputt');
    }

    public function testCsvWithGermanExcelConventions(): void
    {
        $csv = "\xEF\xBB\xBFtype;product;carrier;option;name;zone;countries;max_kg;price_net;per_kg_net;per_100g_net;round_kg;amount_net;percent;kind;condition;limits;postcodes;months;pass_on\r\n"
            . "product;p1;dhl;dhl;Paket;;;;;;;;;;;;maxKg=31,5 maxDimsCm=120x60x60;;;\r\n"
            . "rate;p1;;;;DE;DE;5;5,50;;;;;;;;;;;\r\n"
            . "rate;p1;;;;DE;DE;31,5;9,00;;;;;;;;;;;\r\n"
            . "rate;p1;;;;Ausland;AT FR;;12,00;;0,5;;;;;;;;;\r\n"
            . "oversize;p1;;;Sperrgut;;;;;;;;20;;;exceedsDimsCm=120x60x60 bulky=1;lengthCm=200 girthCm=360;;;\r\n"
            . "surcharge;p1;;;Maut;fixed;;;;;;;0,15;;;;;;;\r\n"
            . "surcharge;p1;;;Insel;fixed;DE;;;;;;15;;;;;18565 25846-25847;;1\r\n";
        $set = TariffLoader::parse($csv);
        $p = $set->products[0];
        self::assertSame(31.5, $p->maxKg);
        self::assertSame([120.0, 60.0, 60.0], $p->maxDimsCm);
        self::assertCount(2, $p->zones);
        self::assertSame(90000, $p->zones[0]->basePrice(20));
        // 12,00 + 0,45 kg → 5 × 0,50 je 100 g = 14,50
        self::assertSame(145000, $p->zones[1]->basePrice(0.45));
        self::assertTrue($p->surcharges[1]->passOn);
        self::assertTrue($p->surcharges[1]->appliesTo('DE', '25847'));
        self::assertFalse($p->surcharges[1]->appliesTo('DE', '25848'));
    }

    public function testCsvErrorsNameTheLine(): void
    {
        try {
            TariffLoader::parse("type;product\nrate;fehlt\nfoo;x\n");
            self::fail('TariffException erwartet');
        } catch (TariffException $e) {
            $text = implode("\n", $e->errors);
            self::assertStringContainsString('Zeile 3: unbekannter type', $text);
            self::assertStringContainsString('Zeile 2: Produkt „fehlt“', $text);
        }
    }

    public function testCsvRoundTripKeepsData(): void
    {
        $json = (string) file_get_contents(__DIR__ . '/../../examples/tarife-beispiel.json');
        $set = TariffLoader::parse($json);
        $again = TariffLoader::parse(TariffCsv::fromArray($set->data));
        self::assertCount(\count($set->products), $again->products);
        foreach ($set->products as $i => $product) {
            self::assertEquals($product, $again->products[$i], $product->id);
        }
        self::assertSame($set->carrierNames, $again->carrierNames);
        self::assertSame(['warenpost', 'kleinpaket', 'dpd', 'dhl'], $set->options());
    }

    /**
     * Die leere CSV-Vorlage aus dem Reiter „Tarife“: nur Kommentare → kein Produkt; mit entfernten #
     * vor den Beispielzeilen ergibt sie eine gültige Tarifdatei mit allen Zeilentypen.
     */
    public function testBlankCsvTemplateIsValidWhenUncommented(): void
    {
        $csv = (string) file_get_contents(__DIR__ . '/../../src/Resources/app/administration/src/module/nova-versandkosten/vorlage/tarife-vorlage.csv');
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertSame(TariffCsv::COLUMNS, explode(';', trim((string) strtok(substr($csv, 3), "\n"))));

        try {
            TariffLoader::parse($csv);
            self::fail('Ohne Produkte darf die Vorlage nicht als Tarif gelten.');
        } catch (TariffException) {
            self::addToAssertionCount(1);
        }

        $set = TariffLoader::parse((string) preg_replace('/^#(?=[a-z]+;)/m', '', $csv));
        self::assertCount(1, $set->products);
        self::assertSame(['dhl'], $set->options());
        $product = $set->products[0];
        self::assertNotEmpty($product->oversize);
        self::assertCount(3, $product->surcharges);
    }
}
