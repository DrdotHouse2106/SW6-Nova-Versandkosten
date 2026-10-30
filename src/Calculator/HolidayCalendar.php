<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Gesetzliche Feiertage in Deutschland je Bundesland (Standard Hessen) plus frei eintragbare
 * Schließtage (Betriebsferien). Samstag und Sonntag sind nie Werktage.
 */
final class HolidayCalendar
{
    public const STATES = ['BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV', 'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH'];

    /** @var array<int, array<string, string>> Jahr → [Y-m-d => Name] */
    private array $cache = [];

    /** @var array<string, true> */
    private array $closedDays = [];

    /**
     * @param list<string> $closedDays zusätzliche Schließtage „Y-m-d“
     */
    public function __construct(private readonly string $state = 'HE', array $closedDays = [])
    {
        foreach ($closedDays as $day) {
            $this->closedDays[$day] = true;
        }
    }

    /**
     * Schließtage aus Text: je Zeile ein Datum „TT.MM.JJJJ“ oder „JJJJ-MM-TT“, auch Bereiche „von - bis“.
     *
     * @return list<string>
     */
    public static function parseClosedDays(string $text): array
    {
        $days = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+(?:-|–|bis)\s+/u', $line) ?: [];
            $from = self::parseDate($parts[0] ?? '');
            $to = isset($parts[1]) ? self::parseDate($parts[1]) : $from;
            if ($from === null || $to === null || $to < $from) {
                continue;
            }
            for ($d = $from, $n = 0; $d <= $to && $n < 400; $d = $d->modify('+1 day'), ++$n) {
                $days[] = $d->format('Y-m-d');
            }
        }

        return array_values(array_unique($days));
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!d.m.Y', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }

    public function isWorkday(\DateTimeImmutable $date): bool
    {
        if ((int) $date->format('N') >= 6) {
            return false;
        }
        $key = $date->format('Y-m-d');

        return !isset($this->closedDays[$key]) && !isset($this->holidays((int) $date->format('Y'))[$key]);
    }

    /** @return array<string, string> */
    public function holidays(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }

        $easter = (new \DateTimeImmutable(\sprintf('%d-03-21', $year)))->modify(\sprintf('+%d days', self::easterDays($year)));
        $rel = static fn (int $days) => $easter->modify(\sprintf('%+d days', $days))->format('Y-m-d');
        $fix = static fn (string $md) => \sprintf('%d-%s', $year, $md);

        $h = [
            $fix('01-01') => 'Neujahr',
            $rel(-2) => 'Karfreitag',
            $rel(1) => 'Ostermontag',
            $fix('05-01') => 'Tag der Arbeit',
            $rel(39) => 'Christi Himmelfahrt',
            $rel(50) => 'Pfingstmontag',
            $fix('10-03') => 'Tag der Deutschen Einheit',
            $fix('12-25') => '1. Weihnachtstag',
            $fix('12-26') => '2. Weihnachtstag',
        ];

        $s = $this->state;
        if (\in_array($s, ['BW', 'BY', 'ST'], true)) {
            $h[$fix('01-06')] = 'Heilige Drei Könige';
        }
        if (\in_array($s, ['BE', 'MV'], true)) {
            $h[$fix('03-08')] = 'Internationaler Frauentag';
        }
        if (\in_array($s, ['BW', 'BY', 'HE', 'NW', 'RP', 'SL'], true)) {
            $h[$rel(60)] = 'Fronleichnam';
        }
        if ($s === 'SL') {
            $h[$fix('08-15')] = 'Mariä Himmelfahrt';
        }
        if ($s === 'TH') {
            $h[$fix('09-20')] = 'Weltkindertag';
        }
        if (\in_array($s, ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH'], true)) {
            $h[$fix('10-31')] = 'Reformationstag';
        }
        if (\in_array($s, ['BW', 'BY', 'NW', 'RP', 'SL'], true)) {
            $h[$fix('11-01')] = 'Allerheiligen';
        }
        if ($s === 'SN') {
            // Buß- und Bettag: Mittwoch vor dem 23. November
            $d = new \DateTimeImmutable(\sprintf('%d-11-22', $year));
            while ((int) $d->format('N') !== 3) {
                $d = $d->modify('-1 day');
            }
            $h[$d->format('Y-m-d')] = 'Buß- und Bettag';
        }

        return $this->cache[$year] = $h;
    }

    /** Tage vom 21. März bis Ostersonntag (Gauß/Spencer, gregorianisch). */
    private static function easterDays(int $year): int
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $hh = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $hh - $k) % 7;
        $m = intdiv($a + 11 * $hh + 22 * $l, 451);
        $month = intdiv($hh + $l - 7 * $m + 114, 31);
        $day = (($hh + $l - 7 * $m + 114) % 31) + 1;

        $easter = new \DateTimeImmutable(\sprintf('%d-%02d-%02d', $year, $month, $day));

        return (int) (new \DateTimeImmutable(\sprintf('%d-03-21', $year)))->diff($easter)->days;
    }

    public function nextWorkday(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $d = $date;
        for ($i = 0; $i < 60 && !$this->isWorkday($d); ++$i) {
            $d = $d->modify('+1 day');
        }

        return $d;
    }

    public function addWorkdays(\DateTimeImmutable $date, int $days): \DateTimeImmutable
    {
        $d = $date;
        for ($i = 0; $i < $days; ++$i) {
            $d = $this->nextWorkday($d->modify('+1 day'));
        }

        return $d;
    }
}
