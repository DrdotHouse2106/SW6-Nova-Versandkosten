<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Alle Versandoptionen für einen Warenkorb.
 */
final class ShippingResult
{
    /**
     * @param array<string, OptionResult> $options Schlüssel = Option
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $options,
        public readonly Packaging $packaging,
        public readonly float $goodsValue,
        public readonly bool $free,
    ) {
    }

    public function option(string $key): ?OptionResult
    {
        return $this->options[$key] ?? null;
    }

    /** @return array<string, OptionResult> */
    public function available(): array
    {
        return array_filter($this->options, static fn (OptionResult $o) => $o->available);
    }

    public function dimsMissing(): bool
    {
        return $this->packaging->dimsMissing;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'dimsMissing' => $this->packaging->dimsMissing,
            'goodsValue' => $this->goodsValue,
            'free' => $this->free,
            'packages' => array_map(static fn (Package $p) => $p->toArray(), $this->packaging->packages),
            'options' => array_map(static fn (OptionResult $o) => $o->toArray(), $this->options),
        ];
    }
}
