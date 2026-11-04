<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Service\BeipackService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Beipacken: Wird die ursprüngliche Bestellung storniert, nachdem andere Bestellungen zu ihr beigepackt
 * wurden, gehen diese allein raus – bezahlt haben sie aber nur die Differenz (oft 0,00 €). Die
 * Beipack-Bestellungen bekommen deshalb einen internen Hinweis, die Administration eine Benachrichtigung.
 */
class BeipackCancelSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BeipackService $beipack,
        private readonly NotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [StateMachineTransitionEvent::class => 'onTransition'];
    }

    public function onTransition(StateMachineTransitionEvent $event): void
    {
        if ($event->getEntityName() !== 'order' || $event->getToPlace()->getTechnicalName() !== 'cancelled') {
            return;
        }
        $orderId = strtolower($event->getEntityId());
        if (!Uuid::isValid($orderId)) {
            return;
        }

        try {
            $ids = $this->beipack->beipackOrderIds($orderId);
            if ($ids === []) {
                return;
            }
            $number = (string) $this->connection->fetchOne(
                'SELECT order_number FROM `order` WHERE id = :id AND version_id = :live',
                ['id' => Uuid::fromHexToBytes($orderId), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
            );
            $note = \sprintf(
                'BEIPACK: Die Bestellung %s, zu der beigepackt wurde, ist storniert. Diese Bestellung geht allein raus – berechnet wurde nur die Versand-Differenz. Versandkosten prüfen bzw. nachfordern.',
                $number
            );
            $numbers = [];
            foreach ($ids as $id) {
                $this->connection->executeStatement(
                    'UPDATE `order` SET internal_comment = TRIM(CONCAT(:note, COALESCE(internal_comment, \'\'))), updated_at = :now
                      WHERE id = :id AND version_id = :live',
                    [
                        'note' => $note . "\n",
                        'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                        'id' => Uuid::fromHexToBytes($id),
                        'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                    ]
                );
                $numbers[] = (string) ($this->beipack->load($id)['order_number'] ?? $id);
            }

            $this->notifications->createNotification([
                'id' => Uuid::randomHex(),
                'status' => 'warning',
                'message' => mb_substr(\sprintf(
                    'Nova Versandkosten: Bestellung %s wurde storniert. Dazu beigepackt: %s – diese gehen jetzt allein raus, Versandkosten bitte prüfen.',
                    $number,
                    implode(', ', $numbers)
                ), 0, 1000),
                'adminOnly' => false,
                'requiredPrivileges' => ['order:read'],
            ], $event->getContext());
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Hinweis an Beipack-Bestellungen nach Storno fehlgeschlagen', ['order' => $orderId, 'error' => $e->getMessage()]);
        }
    }
}
