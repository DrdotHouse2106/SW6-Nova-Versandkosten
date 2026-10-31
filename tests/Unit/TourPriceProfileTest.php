<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\Tour\Geo;
use Nova\Versandkosten\Calculator\Tour\GeoPoint;
use Nova\Versandkosten\Calculator\Tour\TourDate;
use Nova\Versandkosten\Calculator\Tour\TourInput;
use Nova\Versandkosten\Calculator\Tour\TourMatch;
use Nova\Versandkosten\Calculator\Tour\TourPlanner;
use Nova\Versandkosten\Calculator\Tour\TourPriceProfile;
use Nova\Versandkosten\Calculator\Tour\TourPricing;
use Nova\Versandkosten\Calculator\Tour\TourTier;
use Nova\Versandkosten\Calculator\Tour\TourVariant;
use PHPUnit\Framework\TestCase;

/**
 * Preisprofile der eigenen Tour: der Preis hängt davon ab, wer fährt.
 * Beispiel (generisch): „Profil A“ Spedition 190 € bis 60 km; „Profil B“ 190 € bis 30 km, 290 € bis 60 km.
 * Künstliche Strecke entlang Breite 50,0 von Länge 8,0 bis 9,0.
 */
final class TourPriceProfileTest extends TestCase
{
    private const KM_PER_DEG = Geo::EARTH_RADIUS_KM * M_PI / 180;

    private static function profileA(): TourPriceProfile
    {
        return new TourPriceProfile('pa', 'Profil A', 5000, 10.0, [[60.0, 19000]]);
    }

    private static function profileB(): TourPriceProfile
    {
        return new TourPriceProfile('pb', 'Profil B', 5000, 10.0, [[30.0, 19000], [60.0, 29000]]);
    }

    private static function date(?TourPriceProfile $profile, bool $trailer = true, string $id = 'd1'): TourDate
    {
        $line = new TourVariant(TourVariant::CUSTOM, '', [new GeoPoint(50.0, 8.0), new GeoPoint(50.0, 9.0)]);

        return new TourDate($id, new \DateTimeImmutable('2026-11-14'), new \DateTimeImmutable('2026-11-12'), $trailer, TourDate::STATUS_FIXED, [$line], TourVariant::CUSTOM, '', $profile);
    }

    /** Punkt neben der Strecke mit dieser Abweichung (inkl. Umwegfaktor) */
    private static function at(float $deviationKm, float $factor = TourPricing::DEFAULT_DETOUR_FACTOR): GeoPoint
    {
        return new GeoPoint(50.0 + $deviationKm / $factor / self::KM_PER_DEG, 8.5);
    }

    private static function eval(TourDate $date, float $deviationKm, string $tier, ?TourPricing $pricing = null): TourMatch
    {
        $pricing ??= new TourPricing();

        return TourPlanner::evaluate($date, self::at($deviationKm, $pricing->detourFactor), $tier, $pricing, new \DateTimeImmutable('2026-11-01'));
    }

    public function testFreightTiersPerProfile(): void
    {
        // Profil A: 190 € bis 60 km
        self::assertSame(19000, self::eval(self::date(self::profileA()), 20.0, TourTier::FREIGHT)->priceCents);
        self::assertSame(19000, self::eval(self::date(self::profileA()), 45.0, TourTier::FREIGHT)->priceCents);
        self::assertSame(TourMatch::REASON_TOO_FAR, self::eval(self::date(self::profileA()), 65.0, TourTier::FREIGHT)->reason);

        // Profil B: 190 € bis 30 km, 290 € bis 60 km
        self::assertSame(19000, self::eval(self::date(self::profileB()), 20.0, TourTier::FREIGHT)->priceCents);
        self::assertSame(29000, self::eval(self::date(self::profileB()), 45.0, TourTier::FREIGHT)->priceCents);
        self::assertSame(TourMatch::REASON_TOO_FAR, self::eval(self::date(self::profileB()), 65.0, TourTier::FREIGHT)->reason);

        // Kleinteile: beide 50 € bis 10 km, Hänger bleibt Pflicht für Speditionsware
        self::assertSame(5000, self::eval(self::date(self::profileA()), 8.0, TourTier::SMALL)->priceCents);
        self::assertSame(TourMatch::REASON_TOO_FAR, self::eval(self::date(self::profileA()), 12.0, TourTier::SMALL)->reason);
        self::assertSame(TourMatch::REASON_TRAILER, self::eval(self::date(self::profileA(), false), 20.0, TourTier::FREIGHT)->reason);
    }

    public function testProfileChoicePerDate(): void
    {
        // Gleiche Adresse, zwei Termine mit verschiedenen Profilen → verschiedene Preise
        $point = self::at(45.0);
        $dates = [self::date(self::profileB(), id: 'b'), self::date(self::profileA(), id: 'a'), self::date(null, id: 'std')];
        $prices = [];
        foreach (TourPlanner::available($dates, $point, TourTier::FREIGHT, new TourPricing(), new \DateTimeImmutable('2026-11-01')) as $match) {
            $prices[$match->dateId] = $match->priceCents;
        }
        self::assertSame(['b' => 29000, 'a' => 19000, 'std' => 29000], $prices);

        // Eigener Kleinteile-Preis und eigene Grenze im Profil
        $wide = new TourPriceProfile('pw', 'Weit', 7000, 20.0, []);
        self::assertSame(7000, self::eval(self::date($wide), 15.0, TourTier::SMALL)->priceCents);
        // Leere Speditionsstufen: mit diesem Profil keine Speditionsware
        self::assertSame(TourMatch::REASON_TOO_FAR, self::eval(self::date($wide), 5.0, TourTier::FREIGHT)->reason);
    }

    public function testBackwardCompatibleWithoutProfile(): void
    {
        // Ohne Profil gilt das Standardprofil aus den Einstellungen – wie bis 1.2.0
        $pricing = new TourPricing(4000, 12.0, [[25.0, 15000], [50.0, 25000]]);
        $date = self::date(null);
        self::assertSame($pricing, $date->pricing($pricing));
        self::assertSame(4000, self::eval($date, 11.0, TourTier::SMALL, $pricing)->priceCents);
        self::assertSame(15000, self::eval($date, 20.0, TourTier::FREIGHT, $pricing)->priceCents);
        self::assertSame(25000, self::eval($date, 40.0, TourTier::FREIGHT, $pricing)->priceCents);
        self::assertSame(TourMatch::REASON_TOO_FAR, self::eval($date, 55.0, TourTier::FREIGHT, $pricing)->reason);

        // TourDate ohne Profil-Argument (alte Aufrufe) bleibt gültig
        $old = new TourDate('x', new \DateTimeImmutable('2026-11-14'), new \DateTimeImmutable('2026-11-12'), true, TourDate::STATUS_OPEN, []);
        self::assertNull($old->priceProfile);
    }

    public function testProfileKeepsDetourFactorAndCountries(): void
    {
        $pricing = new TourPricing(5000, 10.0, [[30.0, 19000]], 1.5, ['DE', 'AT']);
        $withProfile = $pricing->withProfile(self::profileB());
        self::assertSame(1.5, $withProfile->detourFactor);
        self::assertSame(['DE', 'AT'], $withProfile->countries);
        self::assertSame([[30.0, 19000], [60.0, 29000]], $withProfile->freightTiers);
        // Abweichung mit Umwegfaktor 1,5: 45 km → Stufe bis 60 km
        self::assertSame(29000, self::eval(self::date(self::profileB()), 45.0, TourTier::FREIGHT, $pricing)->priceCents);
    }

    public function testCorridorUsesProfileReach(): void
    {
        $pricing = new TourPricing(5000, 10.0, [[30.0, 19000]]);
        // Standardprofil reicht nur 30 km, Profil B 60 km
        self::assertNull(TourPlanner::inCorridor(self::date(null), self::at(45.0), $pricing));
        self::assertEqualsWithDelta(45.0, (float) TourPlanner::inCorridor(self::date(self::profileB()), self::at(45.0), $pricing), 0.1);
        // Ohne Hänger: nur Reichweite Kleinteile des Profils
        self::assertNull(TourPlanner::inCorridor(self::date(self::profileB(), false), self::at(20.0), $pricing));
        self::assertSame(60.0, $pricing->withProfile(self::profileB())->maxReachKm(true));
    }

    public function testProfileTiersAndInput(): void
    {
        self::assertSame([[30.0, 19000], [60.0, 29000]], TourPriceProfile::tiersFromArray([[60, 29000], ['km' => 30, 'priceCents' => 19000], ['x'], [-1, 5], 'unsinn']));
        self::assertSame([], TourPriceProfile::tiersFromArray(null));
        self::assertSame("30=190\n60=290.5", TourPriceProfile::tiersToText([[30.0, 19000], [60.0, 29050]]));
        self::assertSame([], TourPricing::parseTiers('', false));
        self::assertSame(TourPricing::DEFAULT_FREIGHT_TIERS, TourPricing::parseTiers(''));

        [$clean, $errors] = TourInput::priceProfile(['name' => ' <b>Profil B</b> ', 'smallPrice' => '50,00', 'smallMaxKm' => '10', 'freightTiers' => "60=290\n30=190"]);
        self::assertSame([], $errors);
        self::assertSame(['name' => 'Profil B', 'smallPriceCents' => 5000, 'smallMaxKm' => 10.0, 'freightTiers' => [[30.0, 19000], [60.0, 29000]]], $clean);

        // Leere Stufen erlaubt (keine Speditionsware), Zahlen auch als Zahl
        [$clean] = TourInput::priceProfile(['name' => 'Nur Kleinteile', 'smallPrice' => 45, 'smallMaxKm' => 12.5, 'freightTiers' => '']);
        self::assertNotNull($clean);
        self::assertSame([], $clean['freightTiers']);
        self::assertSame(4500, $clean['smallPriceCents']);

        [$clean, $errors] = TourInput::priceProfile(['name' => '', 'smallPrice' => 'x', 'smallMaxKm' => '-1', 'freightTiers' => "30=190\nunsinn"]);
        self::assertNull($clean);
        self::assertCount(4, $errors);
    }
}
