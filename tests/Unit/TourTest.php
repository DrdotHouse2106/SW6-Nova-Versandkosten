<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Calculator\Tour\Geo;
use Nova\Versandkosten\Calculator\Tour\GeoPoint;
use Nova\Versandkosten\Calculator\Tour\TourDate;
use Nova\Versandkosten\Calculator\Tour\TourInput;
use Nova\Versandkosten\Calculator\Tour\TourMatch;
use Nova\Versandkosten\Calculator\Tour\TourPlanner;
use Nova\Versandkosten\Calculator\Tour\TourPricing;
use Nova\Versandkosten\Calculator\Tour\TourText;
use Nova\Versandkosten\Calculator\Tour\TourTier;
use Nova\Versandkosten\Calculator\Tour\TourVariant;
use PHPUnit\Framework\TestCase;

/**
 * Eigene Touren: Geometrie, Varianten, Preisstufen, Hänger, Bestellschluss.
 * Alle Koordinaten sind künstlich (Linien entlang eines Breitengrads), keine echten Strecken.
 */
final class TourTest extends TestCase
{
    /** 1 Breitengrad in km */
    private const KM_PER_DEG = Geo::EARTH_RADIUS_KM * M_PI / 180;

    public function testDistance(): void
    {
        $a = new GeoPoint(50.0, 8.0);
        $b = new GeoPoint(51.0, 8.0);
        self::assertEqualsWithDelta(self::KM_PER_DEG, Geo::distanceKm($a, $b), 0.01);
        self::assertSame(0.0, Geo::distanceKm($a, $a));
    }

    public function testDistanceToPolyline(): void
    {
        $line = [new GeoPoint(50.0, 8.0), new GeoPoint(50.0, 9.0), new GeoPoint(50.0, 10.0)];

        // Punkt 0,1° nördlich der Mitte des ersten Abschnitts
        $r = Geo::toPolyline(new GeoPoint(50.1, 8.5), $line);
        self::assertNotNull($r);
        self::assertEqualsWithDelta(0.1 * self::KM_PER_DEG, $r['distance'], 0.1);
        self::assertEqualsWithDelta(Geo::distanceKm($line[0], $line[1]) / 2, $r['along'], 0.5);

        // Punkt neben dem zweiten Abschnitt: Position entlang > erster Abschnitt
        $r2 = Geo::toPolyline(new GeoPoint(49.95, 9.5), $line);
        self::assertNotNull($r2);
        self::assertEqualsWithDelta(0.05 * self::KM_PER_DEG, $r2['distance'], 0.1);
        self::assertGreaterThan(Geo::distanceKm($line[0], $line[1]), $r2['along']);

        // Punkt hinter dem Ende: Abstand zum Endpunkt
        $end = new GeoPoint(50.0, 10.2);
        $r3 = Geo::toPolyline($end, $line);
        self::assertNotNull($r3);
        self::assertEqualsWithDelta(Geo::distanceKm($end, $line[2]), $r3['distance'], 0.05);

        // Ein einzelner Wegpunkt: Abstand zu ihm; leere Linie: null
        self::assertEqualsWithDelta(self::KM_PER_DEG, Geo::toPolyline(new GeoPoint(51.0, 8.0), [$line[0]])['distance'] ?? 0, 0.01);
        self::assertNull(Geo::toPolyline($end, []));
        self::assertEqualsWithDelta(2 * Geo::distanceKm($line[0], $line[1]), Geo::length($line), 0.01);
    }

    public function testTier(): void
    {
        $parcel = new Item('a', 'A', 1.0, null, null, null, Item::PARCEL);
        $bulky = new Item('b', 'B', 9.0, null, null, null, Item::BULKY);
        $freight = new Item('c', 'C', 80.0, null, null, null, Item::FREIGHT);
        $pickup = new Item('d', 'D', 1.0, null, null, null, Item::PICKUP_ONLY);

        self::assertSame(TourTier::SMALL, TourTier::fromItems([$parcel, $bulky]));
        self::assertSame(TourTier::FREIGHT, TourTier::fromItems([$parcel, $freight]));
        self::assertNull(TourTier::fromItems([$parcel, $pickup]));
        self::assertNull(TourTier::fromItems([]));
    }

    public function testPricing(): void
    {
        $p = new TourPricing();
        self::assertSame(5000, $p->priceCents(TourTier::SMALL, 9.9, false));
        self::assertSame(5000, $p->priceCents(TourTier::SMALL, 10.0, true));
        self::assertNull($p->priceCents(TourTier::SMALL, 10.1, true));
        // Speditionsware nur mit Hänger – dann auch unter 10 km 190 €
        self::assertNull($p->priceCents(TourTier::FREIGHT, 5.0, false));
        self::assertSame(19000, $p->priceCents(TourTier::FREIGHT, 5.0, true));
        self::assertSame(19000, $p->priceCents(TourTier::FREIGHT, 30.0, true));
        self::assertSame(29000, $p->priceCents(TourTier::FREIGHT, 30.1, true));
        self::assertSame(29000, $p->priceCents(TourTier::FREIGHT, 60.0, true));
        self::assertNull($p->priceCents(TourTier::FREIGHT, 60.1, true));
        self::assertSame(13.0, $p->deviation(10.0));
        self::assertSame(60.0, $p->maxReachKm(true));
        self::assertSame(10.0, $p->maxReachKm(false));

        self::assertSame([[25.0, 15000], [50.0, 25050]], TourPricing::parseTiers("50 km = 250,50 €\n# Kommentar\n25=150\nunsinn"));
        self::assertSame(TourPricing::DEFAULT_FREIGHT_TIERS, TourPricing::parseTiers(''));
        self::assertTrue($p->isCountryAllowed('de'));
        self::assertFalse($p->isCountryAllowed('AT'));
    }

    /**
     * Zwei Varianten zum selben Ziel: A nördlich (Breite 50,2), B südlich (Breite 49,8).
     */
    private function date(string $status = TourDate::STATUS_OPEN, ?string $fixed = null, bool $trailer = true, string $deadline = '2026-11-12'): TourDate
    {
        $a = new TourVariant('va', 'über Nord', [new GeoPoint(50.0, 8.0), new GeoPoint(50.2, 8.5), new GeoPoint(50.0, 9.0)]);
        $b = new TourVariant('vb', 'über Süd', [new GeoPoint(50.0, 8.0), new GeoPoint(49.8, 8.5), new GeoPoint(50.0, 9.0)]);

        return new TourDate('d1', new \DateTimeImmutable('2026-11-14'), new \DateTimeImmutable($deadline), $trailer, $status, [$a, $b], $fixed);
    }

    public function testOpenDateChoosesNearestVariant(): void
    {
        $pricing = new TourPricing();
        $today = new \DateTimeImmutable('2026-11-01');

        $north = TourPlanner::evaluate($this->date(), new GeoPoint(50.22, 8.5), TourTier::SMALL, $pricing, $today);
        self::assertTrue($north->available);
        self::assertSame('va', $north->variantId);
        self::assertSame(5000, $north->priceCents);
        self::assertEqualsWithDelta(0.02 * self::KM_PER_DEG * 1.3, (float) $north->deviationKm, 0.2);

        $south = TourPlanner::evaluate($this->date(), new GeoPoint(49.79, 8.5), TourTier::SMALL, $pricing, $today);
        self::assertTrue($south->available);
        self::assertSame('vb', $south->variantId);
    }

    public function testFixedDateOnlyFixedVariant(): void
    {
        $pricing = new TourPricing();
        $today = new \DateTimeImmutable('2026-11-01');
        $fixed = $this->date(TourDate::STATUS_FIXED, 'va');

        // Kunde an Variante B: bei festgelegter Variante A zu weit weg (ca. 0,4° ≈ 44 km × 1,3)
        $south = TourPlanner::evaluate($fixed, new GeoPoint(49.79, 8.5), TourTier::SMALL, $pricing, $today);
        self::assertFalse($south->available);
        self::assertSame(TourMatch::REASON_TOO_FAR, $south->reason);
        self::assertSame('va', $south->variantId);

        // Speditionsware: 0,4° ≈ 44 km × 1,3 ≈ 58 km → Stufe bis 60 km
        $freight = TourPlanner::evaluate($fixed, new GeoPoint(49.8, 8.5), TourTier::FREIGHT, $pricing, $today);
        self::assertTrue($freight->available);
        self::assertSame(29000, $freight->priceCents);

        // Festgelegte Variante existiert nicht mehr → nichts buchbar
        $broken = TourPlanner::evaluate($this->date(TourDate::STATUS_FIXED, 'weg'), new GeoPoint(50.2, 8.5), TourTier::SMALL, $pricing, $today);
        self::assertSame(TourMatch::REASON_NO_ROUTE, $broken->reason);
    }

    public function testTrailerStatusAndDeadline(): void
    {
        $pricing = new TourPricing();
        $point = new GeoPoint(50.2, 8.5);

        $noTrailer = TourPlanner::evaluate($this->date(trailer: false), $point, TourTier::FREIGHT, $pricing, new \DateTimeImmutable('2026-11-01'));
        self::assertSame(TourMatch::REASON_TRAILER, $noTrailer->reason);
        self::assertTrue(TourPlanner::evaluate($this->date(trailer: false), $point, TourTier::SMALL, $pricing, new \DateTimeImmutable('2026-11-01'))->available);

        $closed = TourPlanner::evaluate($this->date(TourDate::STATUS_CLOSED), $point, TourTier::SMALL, $pricing, new \DateTimeImmutable('2026-11-01'));
        self::assertSame(TourMatch::REASON_CLOSED, $closed->reason);

        // Bestellschluss ist der letzte Bestelltag (inklusive)
        self::assertTrue(TourPlanner::evaluate($this->date(), $point, TourTier::SMALL, $pricing, new \DateTimeImmutable('2026-11-12 23:30'))->available);
        $late = TourPlanner::evaluate($this->date(), $point, TourTier::SMALL, $pricing, new \DateTimeImmutable('2026-11-13 00:10'));
        self::assertSame(TourMatch::REASON_DEADLINE, $late->reason);
        // Bestellschluss nach dem Termin (Tippfehler): Termin selbst begrenzt
        self::assertSame(TourMatch::REASON_DEADLINE, TourPlanner::evaluate($this->date(deadline: '2026-11-20'), $point, TourTier::SMALL, $pricing, new \DateTimeImmutable('2026-11-15'))->reason);

        self::assertSame(TourMatch::REASON_TIER, TourPlanner::evaluate($this->date(), $point, null, $pricing, new \DateTimeImmutable('2026-11-01'))->reason);
        self::assertSame('2026-11-12', TourDate::defaultDeadline(new \DateTimeImmutable('2026-11-14'))->format('Y-m-d'));
    }

    public function testAvailableSortedAndCorridor(): void
    {
        $pricing = new TourPricing();
        $later = new TourDate('d2', new \DateTimeImmutable('2026-11-21'), new \DateTimeImmutable('2026-11-19'), false, TourDate::STATUS_OPEN, [new TourVariant(TourVariant::CUSTOM, '', [new GeoPoint(50.2, 8.0), new GeoPoint(50.2, 9.0)])]);
        $list = TourPlanner::available([$later, $this->date()], new GeoPoint(50.2, 8.5), TourTier::SMALL, $pricing, new \DateTimeImmutable('2026-11-01'));
        self::assertSame(['d1', 'd2'], array_map(static fn (TourMatch $m) => $m->dateId, $list));

        // Korridor: mit Hänger bis 60 km, ohne bis 10 km
        self::assertNotNull(TourPlanner::inCorridor($this->date(), new GeoPoint(50.6, 8.5), $pricing));
        self::assertNull(TourPlanner::inCorridor($this->date(trailer: false), new GeoPoint(50.6, 8.5), $pricing));
        self::assertNull(TourPlanner::inCorridor($this->date(), new GeoPoint(51.0, 8.5), $pricing));
    }

    public function testTexts(): void
    {
        $date = new \DateTimeImmutable('2026-11-14');
        self::assertSame('Sa, 14.11.2026', TourText::date($date));
        self::assertSame('Sat, 14/11/2026', TourText::date($date, 'en-GB'));
        self::assertSame('Samstag, 14.11.2026', TourText::longDate($date));
        self::assertSame('TOUR: Sa, 14.11.2026', TourText::comment($date, null));
        self::assertSame("TOUR: Sa, 14.11.2026\nBitte klingeln", TourText::comment($date, 'Bitte klingeln'));
        self::assertSame('TOUR: Sa, 14.11.2026', TourText::comment($date, 'TOUR: Sa, 14.11.2026'));
        self::assertSame('12…', TourText::area('12345'));
        self::assertSame([], TourVariant::pointsFromArray('x'));
        self::assertCount(1, TourVariant::pointsFromArray([['lat' => 50, 'lon' => 8], ['lat' => 'a'], ['lat' => 99, 'lon' => 1]]));
    }

    public function testAdminInput(): void
    {
        $points = TourInput::waypoints([
            ['label' => '<b>Start</b>', 'postcode' => '12345', 'lat' => '50.123456', 'lon' => 8.1],
            ['label' => 'kaputt', 'lat' => 'x', 'lon' => 1],
            'unsinn',
            ['label' => 'Ziel', 'lat' => 51, 'lon' => 9],
        ]);
        self::assertCount(2, $points);
        self::assertSame('Start', $points[0]['label']);
        self::assertSame(50.12346, $points[0]['lat']);

        [$date, $errors] = TourInput::tourDate(['tourDate' => '2026-11-14', 'routeId' => 'abc', 'trailer' => 1]);
        self::assertSame([], $errors);
        self::assertNotNull($date);
        self::assertSame('2026-11-12', $date['orderDeadline']);
        self::assertSame(TourDate::STATUS_OPEN, $date['status']);
        self::assertTrue($date['trailer']);

        // Bestellschluss nach dem Termin wird begrenzt
        [$late] = TourInput::tourDate(['tourDate' => '2026-11-14', 'orderDeadline' => '2026-11-20', 'routeId' => 'abc', 'status' => 'fixed']);
        self::assertSame('2026-11-14', $late['orderDeadline'] ?? null);
        self::assertSame('fixed', $late['status'] ?? null);

        // Ohne Strecke mindestens zwei Wegpunkte; ungültiges Datum
        self::assertNotSame([], TourInput::tourDate(['tourDate' => '2026-11-14', 'waypoints' => [$points[0]]])[1]);
        self::assertSame([], TourInput::tourDate(['tourDate' => '2026-11-14', 'waypoints' => $points])[1]);
        self::assertNotSame([], TourInput::tourDate(['tourDate' => '2026-02-30', 'routeId' => 'abc'])[1]);
    }
}
