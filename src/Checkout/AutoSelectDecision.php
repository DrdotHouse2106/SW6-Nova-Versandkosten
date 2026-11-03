<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\OptionResult;

/**
 * Reine Entscheidung für die automatische Vorauswahl (ohne Shopware, testbar).
 *
 * - Ist die gewählte Versandart gesperrt, wird auf die günstigste mögliche Paket-Versandart gewechselt
 *   (Warenpost, Kleinpaket, DPD, DHL, Spedition); gibt es keine, auf „Versandkosten auf Anfrage“.
 * - Ist eine Paket-Versandart des Plugins gewählt, die der Kunde nicht selbst ausgesucht hat, wird auf
 *   die günstigste gewechselt. Eine selbst gewählte Versandart bleibt, solange sie möglich ist.
 * - Fremde Versandarten (Abholung, alte Versandarten …) werden nur angefasst, wenn sie gesperrt sind
 *   und es eine passende Plugin-Versandart gibt – sonst entscheidet Shopware.
 */
final class AutoSelectDecision
{
    /**
     * @param string|null                               $currentKey  Option der gewählten Versandart (null = fremde Versandart)
     * @param array<string, array{id: string, price: float}> $candidates  mögliche Plugin-Versandarten je Option
     *
     * @return string|null ID der neuen Versandart oder null (nichts ändern)
     */
    public static function choose(?string $currentKey, string $currentId, bool $blocked, bool $manual, array $candidates): ?string
    {
        $best = self::cheapest($candidates);

        if ($blocked) {
            $target = $best ?? ($candidates[OptionResult::REQUEST]['id'] ?? null);

            return $target !== null && $target !== $currentId ? $target : null;
        }

        if ($manual || $best === null || $currentKey === null || !\in_array($currentKey, OptionResult::SHIPPING, true)) {
            return null;
        }

        $currentPrice = $candidates[$currentKey]['price'] ?? null;
        $bestPrice = self::priceOf($candidates, $best);
        if ($best === $currentId || ($currentPrice !== null && $currentPrice <= $bestPrice + 0.0001)) {
            return null;
        }

        return $best;
    }

    /**
     * @param array<string, array{id: string, price: float}> $candidates
     */
    private static function cheapest(array $candidates): ?string
    {
        $bestId = null;
        $bestPrice = null;
        // Reihenfolge von OptionResult::SHIPPING entscheidet bei Gleichstand (Warenpost vor Kleinpaket …)
        foreach (OptionResult::SHIPPING as $key) {
            if (!isset($candidates[$key])) {
                continue;
            }
            $price = $candidates[$key]['price'];
            if ($bestPrice === null || $price < $bestPrice - 0.0001) {
                $bestPrice = $price;
                $bestId = $candidates[$key]['id'];
            }
        }

        return $bestId;
    }

    /**
     * @param array<string, array{id: string, price: float}> $candidates
     */
    private static function priceOf(array $candidates, string $id): float
    {
        foreach ($candidates as $candidate) {
            if ($candidate['id'] === $id) {
                return $candidate['price'];
            }
        }

        return 0.0;
    }
}
