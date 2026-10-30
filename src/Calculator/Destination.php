<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

final class Destination
{
    public readonly string $country;

    public readonly string $postcode;

    public function __construct(string $country, string $postcode = '')
    {
        $this->country = strtoupper(trim($country));
        $this->postcode = trim($postcode);
    }
}
