<?php declare(strict_types=1);

namespace Nova\Versandkosten\ScheduledTask;

use Nova\Versandkosten\Service\Tour\TourNotificationService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: TourCleanupTask::class)]
final class TourCleanupTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly TourNotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $deleted = $this->notifications->cleanup();
        if ($deleted['pending'] + $deleted['inactive'] > 0) {
            $this->logger->info('Nova Versandkosten: Tour-Benachrichtigung aufgeräumt', $deleted);
        }
    }
}
