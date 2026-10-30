<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\DeliveryEstimator;
use Nova\Versandkosten\Calculator\HolidayCalendar;
use PHPUnit\Framework\TestCase;

final class CalendarTest extends TestCase
{
    public function testHessianHolidays(): void
    {
        $he = new HolidayCalendar('HE');
        $h2027 = $he->holidays(2027);
        self::assertArrayHasKey('2027-03-26', $h2027); // Karfreitag (Ostern 28.03.2027)
        self::assertArrayHasKey('2027-03-29', $h2027); // Ostermontag
        self::assertArrayHasKey('2027-05-06', $h2027); // Himmelfahrt
        self::assertArrayHasKey('2027-05-17', $h2027); // Pfingstmontag
        self::assertArrayHasKey('2027-05-27', $h2027); // Fronleichnam (Hessen)
        self::assertArrayNotHasKey('2027-10-31', $h2027); // Reformationstag nicht in Hessen
        self::assertArrayHasKey('2026-04-06', $he->holidays(2026)); // Ostermontag 2026

        $sn = new HolidayCalendar('SN');
        self::assertArrayHasKey('2026-11-18', $sn->holidays(2026)); // Buß- und Bettag
        self::assertArrayHasKey('2026-10-31', $sn->holidays(2026));
    }

    public function testClosedDaysParsing(): void
    {
        $days = HolidayCalendar::parseClosedDays("24.12.2026 - 31.12.2026\n2027-01-02");
        self::assertCount(9, $days);
        $cal = new HolidayCalendar('HE', $days);
        self::assertFalse($cal->isWorkday(new \DateTimeImmutable('2026-12-28')));
        self::assertTrue($cal->isWorkday(new \DateTimeImmutable('2027-01-04')));
    }

    public function testEstimateBeforeAndAfterCutoff(): void
    {
        $est = new DeliveryEstimator(new HolidayCalendar('HE'), '12:00', 0, 1);

        // Montag 02.11.2026 10:00, Laufzeit 1–2 → Versand Mo–Di, Zustellung Di 03.11. – Do 05.11.
        [$from, $to] = $est->estimate(new \DateTimeImmutable('2026-11-02 10:00'), 1, 2);
        self::assertSame('2026-11-03', $from->format('Y-m-d'));
        self::assertSame('2026-11-05', $to->format('Y-m-d'));
        self::assertSame('Di, 03.11. – Do, 05.11.', DeliveryEstimator::formatRange($from, $to));

        // Freitag 30.10.2026 14:00 (nach Annahmeschluss) → Bearbeitung ab Montag
        [$from, $to] = $est->estimate(new \DateTimeImmutable('2026-10-30 14:00'), 1, 2);
        self::assertSame('2026-11-03', $from->format('Y-m-d'));
        self::assertSame('2026-11-05', $to->format('Y-m-d'));

        // Gründonnerstag 25.03.2027 15:00 → Karfreitag/Ostermontag frei, Bearbeitung ab Di 30.03.
        [$from] = $est->estimate(new \DateTimeImmutable('2027-03-25 15:00'), 1, 2);
        self::assertSame('2027-03-31', $from->format('Y-m-d'));

        // Erscheinungsdatum in der Zukunft zählt
        [$from] = $est->estimate(new \DateTimeImmutable('2026-11-02 10:00'), 1, 2, new \DateTimeImmutable('2026-11-20'));
        self::assertSame('2026-11-23', $from->format('Y-m-d'));
        self::assertSame('Tue, 03.11.', DeliveryEstimator::formatDay(new \DateTimeImmutable('2026-11-03'), 'en-GB'));
    }
}
