<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Ein Tourtermin.
 *
 * Status: „open“ = alle Varianten zählen (noch keine Buchung), „fixed“ = Variante festgelegt (erste
 * Buchung oder Admin), „closed“ = keine Buchungen mehr. Bestellschluss = letzter Bestelltag (inklusive).
 */
final class TourDate
{
    public const STATUS_OPEN = 'open';
    public const STATUS_FIXED = 'fixed';
    public const STATUS_CLOSED = 'closed';
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_FIXED, self::STATUS_CLOSED];

    /** Standard-Bestellschluss: so viele Tage vor dem Termin */
    public const DEFAULT_DEADLINE_DAYS = 2;

    /**
     * @param list<TourVariant> $variants
     */
    public function __construct(
        public readonly string $id,
        public readonly \DateTimeImmutable $date,
        public readonly \DateTimeImmutable $deadline,
        public readonly bool $trailer,
        public readonly string $status,
        public readonly array $variants,
        public readonly ?string $fixedVariantId = null,
        public readonly string $name = '',
    ) {
    }

    /**
     * Varianten, an denen gebucht werden kann: bei festgelegter Variante nur diese, sonst alle.
     *
     * @return list<TourVariant>
     */
    public function bookableVariants(): array
    {
        if ($this->status === self::STATUS_FIXED && $this->fixedVariantId !== null) {
            foreach ($this->variants as $variant) {
                if ($variant->id === $this->fixedVariantId) {
                    return [$variant];
                }
            }

            return [];
        }

        return $this->variants;
    }

    /** Bestellschluss vorbei? (Tage in der Zeitzone des Shops vergleichen) */
    public function isPastDeadline(\DateTimeImmutable $today): bool
    {
        $day = $today->format('Y-m-d');

        return $day > $this->deadline->format('Y-m-d') || $day > $this->date->format('Y-m-d');
    }

    /** Standard-Bestellschluss zu einem Datum */
    public static function defaultDeadline(\DateTimeImmutable $date, int $days = self::DEFAULT_DEADLINE_DAYS): \DateTimeImmutable
    {
        return $date->modify('-' . max(0, $days) . ' days');
    }
}
