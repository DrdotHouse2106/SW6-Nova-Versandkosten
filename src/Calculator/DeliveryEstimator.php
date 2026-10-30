<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Voraussichtliches Zustelldatum (Rechenregel Abschnitt 6).
 *
 * Bearbeitung: vor Annahmeschluss an einem Werktag bestellt → Bearbeitung beginnt heute, sonst am
 * nächsten Werktag. Versand frühestens nach processingMin, spätestens nach processingMax Werktagen.
 * Zustellung: Versandtag + Laufzeit (Werktage Mo–Fr, Feiertage des eingestellten Bundeslands).
 * Ist ein Artikel erst später lieferbar (z. B. Erscheinungsdatum), zählt der spätere Termin.
 */
final class DeliveryEstimator
{
    public function __construct(
        private readonly HolidayCalendar $calendar,
        private readonly string $cutoff = '12:00',
        private readonly int $processingMin = 0,
        private readonly int $processingMax = 1,
    ) {
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function estimate(\DateTimeImmutable $now, int $transitMin, int $transitMax, ?\DateTimeImmutable $availableFrom = null): array
    {
        $today = $now->setTime(0, 0);
        [$hour, $minute] = array_map('intval', explode(':', $this->cutoff . ':0'));
        $cutoff = $today->setTime($hour, $minute);

        $start = ($this->calendar->isWorkday($today) && $now < $cutoff) ? $today : $this->calendar->nextWorkday($today->modify('+1 day'));
        if ($availableFrom !== null && $availableFrom->setTime(0, 0) > $start) {
            $start = $this->calendar->nextWorkday($availableFrom->setTime(0, 0));
        }

        $shipEarliest = $this->calendar->addWorkdays($start, max(0, $this->processingMin));
        $shipLatest = $this->calendar->addWorkdays($start, max($this->processingMin, $this->processingMax));

        return [
            $this->calendar->addWorkdays($shipEarliest, max(0, $transitMin)),
            $this->calendar->addWorkdays($shipLatest, max($transitMin, $transitMax)),
        ];
    }

    /** „Di, 04.11.“ (de) bzw. „Tue, 04.11.“ (en) */
    public static function formatDay(\DateTimeImmutable $date, string $locale = 'de'): string
    {
        $de = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $en = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $names = str_starts_with(strtolower($locale), 'de') ? $de : $en;

        return $names[(int) $date->format('N') - 1] . ', ' . $date->format('d.m.');
    }

    /** „Di, 04.11. – Mi, 05.11.“ bzw. ein Tag, wenn beide gleich sind */
    public static function formatRange(\DateTimeImmutable $from, \DateTimeImmutable $to, string $locale = 'de'): string
    {
        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return self::formatDay($from, $locale);
        }

        return self::formatDay($from, $locale) . ' – ' . self::formatDay($to, $locale);
    }
}
