<?php declare(strict_types=1);

namespace Nova\Versandkosten\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Täglich: offene Bestellungen „Versandkosten auf Anfrage“ ohne Zahlung nach X Tagen stornieren.
 */
class RequestCancelTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'nova_versandkosten.request_cancel';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
