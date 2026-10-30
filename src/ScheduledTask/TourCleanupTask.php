<?php declare(strict_types=1);

namespace Nova\Versandkosten\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Täglich: Tour-Benachrichtigung aufräumen – unbestätigte Anmeldungen nach 7 Tagen, aktive Abonnenten
 * nach X Monaten ohne Aktivität (Einstellung, Standard 24) löschen.
 */
class TourCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'nova_versandkosten.tour_cleanup';
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
