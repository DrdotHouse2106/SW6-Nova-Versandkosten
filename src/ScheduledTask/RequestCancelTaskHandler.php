<?php declare(strict_types=1);

namespace Nova\Versandkosten\ScheduledTask;

use Nova\Versandkosten\Service\RequestOrderService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: RequestCancelTask::class)]
final class RequestCancelTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly RequestOrderService $requests,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $cancelled = $this->requests->cancelDue(Context::createDefaultContext());
        if ($cancelled !== []) {
            $this->logger->info('Nova Versandkosten: Anfrage-Bestellungen ohne Zahlung storniert', ['orders' => $cancelled]);
        }
    }
}
