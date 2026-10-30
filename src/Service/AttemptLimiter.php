<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Versuchsbegrenzung für „Bestellnummer + E-Mail + PLZ“ (Standard 5 Versuche je Stunde und IP).
 * Gezählt werden alle Versuche im gleitenden Zeitfenster; gespeichert wird nur ein Hash der IP.
 */
class AttemptLimiter
{
    public const LIMIT = 5;
    public const WINDOW_SECONDS = 3600;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    /**
     * Zählt einen Versuch. false = Grenze erreicht (Versuch nicht erlaubt).
     */
    public function attempt(string $scope, string $clientKey): bool
    {
        $item = $this->cache->getItem('nova_versandkosten_limit_' . hash('sha256', $scope . '|' . $clientKey));
        $stored = $item->get();
        [$allowed, $timestamps] = self::register(\is_array($stored) ? array_values(array_filter($stored, 'is_int')) : [], time());
        $item->set($timestamps);
        $item->expiresAfter(self::WINDOW_SECONDS);
        $this->cache->save($item);

        return $allowed;
    }

    /**
     * Reine Logik: alte Versuche verwerfen, neuen zählen, wenn unter der Grenze.
     *
     * @param list<int> $timestamps
     *
     * @return array{0: bool, 1: list<int>}
     */
    public static function register(array $timestamps, int $now, int $limit = self::LIMIT, int $window = self::WINDOW_SECONDS): array
    {
        $recent = array_values(array_filter($timestamps, static fn (int $t) => $t > $now - $window));
        if (\count($recent) >= $limit) {
            return [false, $recent];
        }
        $recent[] = $now;

        return [true, $recent];
    }
}
