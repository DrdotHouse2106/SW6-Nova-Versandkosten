<?php declare(strict_types=1);

namespace Nova\Versandkosten\Energy;

/**
 * Ein Wert des Energiezuschlags, gültig ab einem Tag (bis der nächste Eintrag gilt).
 */
final class EnergyEntry
{
    /**
     * @param array<string, float> $components Bestandteile (z. B. langfristig/kurzfristig), nur zur Anzeige
     */
    public function __construct(
        public readonly string $validFrom,
        public readonly float $percent,
        public readonly array $components = [],
    ) {
    }

    /** @return array{validFrom: string, percent: float, components: array<string, float>} */
    public function toArray(): array
    {
        return ['validFrom' => $this->validFrom, 'percent' => $this->percent, 'components' => $this->components];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $from = $data['validFrom'] ?? null;
        $percent = $data['percent'] ?? null;
        if (!\is_string($from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !is_numeric($percent)) {
            return null;
        }
        $components = [];
        foreach ((array) ($data['components'] ?? []) as $k => $v) {
            if (is_numeric($v)) {
                $components[(string) $k] = (float) $v;
            }
        }

        return new self($from, (float) $percent, $components);
    }

    public static function isPlausible(float $percent): bool
    {
        return $percent >= 0.0 && $percent <= 60.0;
    }

    /**
     * Gültiger Eintrag für einen Tag: der jüngste mit „gültig ab“ ≤ Tag.
     *
     * @param list<self> $entries
     */
    public static function valueAt(array $entries, \DateTimeImmutable $date): ?self
    {
        $day = $date->format('Y-m-d');
        $best = null;
        foreach ($entries as $entry) {
            if ($entry->validFrom <= $day && ($best === null || $entry->validFrom > $best->validFrom)) {
                $best = $entry;
            }
        }

        return $best;
    }
}
