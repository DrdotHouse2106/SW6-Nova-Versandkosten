<?php declare(strict_types=1);

namespace Nova\Versandkosten\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Täglich: Energiezuschläge von DHL und DPD abrufen.
 */
class EnergyFetchTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'nova_versandkosten.energy_fetch';
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
