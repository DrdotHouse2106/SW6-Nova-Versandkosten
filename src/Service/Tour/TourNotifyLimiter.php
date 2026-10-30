<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service\Tour;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Mengenbegrenzung für das Formular „Tour-Benachrichtigung“ (Symfony RateLimiter): je IP-Adresse
 * 3 Anmeldungen pro Stunde, je Verkaufskanal 100 pro Tag. Im Cache landet nur ein Hash der IP.
 */
class TourNotifyLimiter
{
    public const PER_IP_PER_HOUR = 3;
    public const PER_CHANNEL_PER_DAY = 100;

    private ?RateLimiterFactory $ipFactory = null;

    private ?RateLimiterFactory $channelFactory = null;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function consume(string $clientIp, string $salesChannelId): bool
    {
        try {
            $this->ipFactory ??= new RateLimiterFactory(
                ['id' => 'nova-versandkosten-tour-notify-ip', 'policy' => 'sliding_window', 'limit' => self::PER_IP_PER_HOUR, 'interval' => '1 hour'],
                new CacheStorage($this->cache)
            );
            $this->channelFactory ??= new RateLimiterFactory(
                ['id' => 'nova-versandkosten-tour-notify-channel', 'policy' => 'fixed_window', 'limit' => self::PER_CHANNEL_PER_DAY, 'interval' => '1 day'],
                new CacheStorage($this->cache)
            );

            return $this->ipFactory->create(hash('sha256', $salesChannelId . '|' . $clientIp))->consume()->isAccepted()
                && $this->channelFactory->create($salesChannelId)->consume()->isAccepted();
        } catch (\Throwable) {
            // Ohne funktionierenden Cache lieber ablehnen als ungebremst annehmen
            return false;
        }
    }
}
