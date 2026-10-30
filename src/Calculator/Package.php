<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Ein gebildetes Paket. Maße absteigend sortiert (Länge ≥ Breite ≥ Höhe), in cm.
 */
final class Package
{
    /** @var array{0: float, 1: float, 2: float} */
    public array $dims;

    /** @var list<string> Bezeichnungen der enthaltenen Stücke */
    public array $contents = [];

    public function __construct(
        public float $weightKg,
        float $length,
        float $width,
        float $height,
        public bool $bulky = false,
    ) {
        $this->dims = self::sortDims($length, $width, $height);
    }

    /** @return array{0: float, 1: float, 2: float} */
    public static function sortDims(float $a, float $b, float $c): array
    {
        $d = [$a, $b, $c];
        rsort($d);

        return [(float) $d[0], (float) $d[1], (float) $d[2]];
    }

    /** Größter Artikel bestimmt das Paketmaß: je sortierter Kante das Maximum. */
    public function growTo(float $length, float $width, float $height): void
    {
        $other = self::sortDims($length, $width, $height);
        $this->dims = [
            max($this->dims[0], $other[0]),
            max($this->dims[1], $other[1]),
            max($this->dims[2], $other[2]),
        ];
    }

    public function length(): float
    {
        return $this->dims[0];
    }

    /** Gurtmaß = Länge + 2 × Breite + 2 × Höhe */
    public function girth(): float
    {
        return $this->dims[0] + 2 * $this->dims[1] + 2 * $this->dims[2];
    }

    public function volumeLiters(): float
    {
        return $this->dims[0] * $this->dims[1] * $this->dims[2] / 1000;
    }

    /** Passt das Paket in einen Quader (beliebig gedreht)? */
    public function fitsInto(float $a, float $b, float $c): bool
    {
        $box = self::sortDims($a, $b, $c);

        return $this->dims[0] <= $box[0] + 1e-9 && $this->dims[1] <= $box[1] + 1e-9 && $this->dims[2] <= $box[2] + 1e-9;
    }

    /** @return array{weightKg: float, lengthCm: float, widthCm: float, heightCm: float, bulky: bool, contents: list<string>} */
    public function toArray(): array
    {
        return [
            'weightKg' => round($this->weightKg, 3),
            'lengthCm' => round($this->dims[0], 1),
            'widthCm' => round($this->dims[1], 1),
            'heightCm' => round($this->dims[2], 1),
            'bulky' => $this->bulky,
            'contents' => $this->contents,
        ];
    }
}
