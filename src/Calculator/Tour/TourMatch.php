<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Ergebnis „passt diese Lieferadresse zu diesem Tourtermin?“.
 */
final class TourMatch
{
    public const REASON_CLOSED = 'closed';
    public const REASON_DEADLINE = 'deadline';
    public const REASON_TIER = 'tier';
    public const REASON_TRAILER = 'trailer';
    public const REASON_NO_ROUTE = 'noRoute';
    public const REASON_TOO_FAR = 'tooFar';

    public function __construct(
        public readonly string $dateId,
        public readonly bool $available,
        public readonly string $reason = '',
        public readonly ?string $variantId = null,
        public readonly string $variantName = '',
        /** Abweichung inkl. Umwegfaktor, km */
        public readonly ?float $deviationKm = null,
        /** Position entlang der Variante ab Start, km (Lade-Reihenfolge) */
        public readonly ?float $alongKm = null,
        public readonly ?int $priceCents = null,
        public readonly ?string $tier = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dateId' => $this->dateId,
            'available' => $this->available,
            'reason' => $this->reason,
            'variantId' => $this->variantId,
            'variantName' => $this->variantName,
            'deviationKm' => $this->deviationKm === null ? null : round($this->deviationKm, 1),
            'alongKm' => $this->alongKm === null ? null : round($this->alongKm, 1),
            'priceCents' => $this->priceCents,
            'tier' => $this->tier,
        ];
    }
}
