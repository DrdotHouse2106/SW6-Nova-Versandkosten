<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\RequestOrder;
use Nova\Versandkosten\Installer\CustomFieldInstaller as CF;
use Nova\Versandkosten\Installer\MailTemplateInstaller;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Order\RecalculationService;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * „Versandkosten auf Anfrage“: Shop benachrichtigen, Preis eintragen (Bestellung über Shopwares
 * Nachberechnung neu rechnen, Steuern stimmen danach), Kunde benachrichtigen, offene Anfragen nach
 * X Tagen ohne Zahlung stornieren.
 */
class RequestOrderService
{
    /** Zahlungsstatus, deren Betrag nach dem Eintragen angepasst werden darf */
    private const OPEN_TRANSACTION_STATES = ['open', 'reminded', 'unconfirmed', 'failed'];

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orders
     * @param EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection> $deliveries
     * @param EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> $transactions
     */
    public function __construct(
        private readonly EntityRepository $orders,
        private readonly EntityRepository $deliveries,
        private readonly EntityRepository $transactions,
        private readonly RecalculationService $recalculation,
        private readonly AbstractMailService $mailService,
        private readonly StateMachineRegistry $stateMachine,
        private readonly Connection $connection,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function isRequestOrder(OrderEntity $order): bool
    {
        return !empty(($order->getCustomFields() ?? [])[CF::ORDER_REQUEST]);
    }

    /**
     * Versandkosten eintragen und (optional) den Kunden benachrichtigen.
     *
     * @return array{shippingCosts: float, total: float, mailSent: bool, errors: list<string>}
     */
    public function applyPrice(string $orderId, float $grossInput, bool $notify, Context $context): array
    {
        $order = $this->loadOrder($orderId, $context);
        if ($order === null) {
            throw new \InvalidArgumentException('Bestellung nicht gefunden.');
        }
        if ($order->getStateMachineState()?->getTechnicalName() === 'cancelled') {
            throw new \InvalidArgumentException('Die Bestellung ist storniert.');
        }
        $delivery = self::mainDelivery($order);
        if ($delivery === null) {
            throw new \InvalidArgumentException('Die Bestellung hat keine Lieferung.');
        }

        $vat = $this->settings->rules($order->getSalesChannelId())->vatPercent;
        $price = RequestOrder::shippingInput($grossInput, $order->getTaxStatus() ?? 'gross', $vat);

        // Shopware-Weg wie „Bestellung bearbeiten“ in der Administration: Version anlegen, Versandkosten
        // als manuellen Preis setzen, nachberechnen (Steuern aus den Positionen), Version übernehmen.
        $versionId = $this->orders->createVersion($orderId, $context);
        $versionContext = $context->createWithVersionId($versionId);
        $merged = false;
        try {
            $this->deliveries->update([[
                'id' => $delivery->getId(),
                'shippingCosts' => new CalculatedPrice($price, $price, new CalculatedTaxCollection(), new TaxRuleCollection()),
            ]], $versionContext);

            $errors = $this->recalculation->recalculate($orderId, $versionContext);

            $recalculated = $this->loadOrder($orderId, $versionContext);
            if ($recalculated === null) {
                throw new \RuntimeException('Bestellung nach der Nachberechnung nicht gefunden.');
            }
            $this->updateOpenTransaction($recalculated, $versionContext);

            $this->orders->update([[
                'id' => $orderId,
                'customFields' => [
                    CF::ORDER_REQUEST_STATUS => RequestOrder::STATUS_PRICED,
                    CF::ORDER_REQUEST_PRICED_AT => (new \DateTimeImmutable())->format(\DATE_ATOM),
                    CF::ORDER_SALE => round($grossInput, 2),
                ],
            ]], $versionContext);

            $this->orders->merge($versionId, $context);
            $merged = true;
        } finally {
            if (!$merged) {
                // Fehlgeschlagen: Arbeitskopie verwerfen (wie „Abbrechen“ beim Bearbeiten in der Administration)
                try {
                    $this->orders->delete([['id' => $orderId]], $versionContext);
                } catch (\Throwable) {
                }
                $this->connection->executeStatement('DELETE FROM version WHERE id = :id', ['id' => Uuid::fromHexToBytes($versionId)]);
            }
        }

        $order = $this->loadOrder($orderId, $context);
        $mailSent = false;
        if ($notify && $order !== null) {
            $mailSent = $this->sendCustomerMail($order, $context);
        }

        return [
            'shippingCosts' => $order?->getShippingTotal() ?? $price,
            'total' => $order?->getAmountTotal() ?? 0.0,
            'mailSent' => $mailSent,
            'errors' => array_values(array_map(static fn ($e) => $e->getMessage(), $errors->getElements())),
        ];
    }

    /**
     * Link zum Bezahlen: Kundenkonto → „Bestellung bearbeiten“ (Zahlungsart wählen/ändern),
     * Gast → Bestellseite mit Shopwares Bestell-Code (Anmeldung mit E-Mail + PLZ).
     */
    public function paymentUrl(OrderEntity $order): string
    {
        $domain = $this->domainUrl($order->getSalesChannelId(), $order->getLanguageId());
        if ($this->isGuestOrder($order) && $order->getDeepLinkCode()) {
            return $domain . '/account/order/' . rawurlencode((string) $order->getDeepLinkCode());
        }

        return $domain . '/account/order/edit/' . $order->getId();
    }

    public function sendCustomerMail(OrderEntity $order, Context $context): bool
    {
        $email = $order->getOrderCustomer()?->getEmail();
        if ($email === null || $email === '') {
            return false;
        }
        $template = $this->mailTemplate($order->getLanguageId());
        if ($template === null) {
            $this->logger->warning('Nova Versandkosten: E-Mail-Vorlage für „Versandkosten nachberechnet“ fehlt');

            return false;
        }

        $mailContext = new Context(new SystemSource(), [], $order->getCurrencyId(), array_values(array_unique([$order->getLanguageId(), Defaults::LANGUAGE_SYSTEM])));
        $name = trim(($order->getOrderCustomer()?->getFirstName() ?? '') . ' ' . ($order->getOrderCustomer()?->getLastName() ?? ''));

        try {
            $this->mailService->send([
                'recipients' => [$email => $name !== '' ? $name : $email],
                'salesChannelId' => $order->getSalesChannelId(),
                'senderName' => $template['sender_name'],
                'subject' => $template['subject'],
                'contentHtml' => $template['content_html'],
                'contentPlain' => $template['content_plain'],
                'templateId' => $template['id'],
            ], $mailContext, [
                'order' => $order,
                'novaVersandkosten' => [
                    'shippingCosts' => $order->getShippingTotal(),
                    'total' => $order->getAmountTotal(),
                    'currencyIsoCode' => $order->getCurrency()?->getIsoCode() ?? 'EUR',
                    'paymentUrl' => $this->paymentUrl($order),
                    'guest' => $this->isGuestOrder($order),
                    'cancelDays' => max(0, $this->settings->int('requestCancelDays', $order->getSalesChannelId(), 14)),
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Kunden-E-Mail „Versandkosten nachberechnet“ fehlgeschlagen', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * E-Mail an den Shop: „Versandkosten eintragen für Bestellung <Nr>“ mit Link in die Administration.
     */
    public function notifyShop(OrderEntity $order, Context $context): void
    {
        $sc = $order->getSalesChannelId();
        $recipient = $this->settings->string('requestNotifyEmail', $sc) ?: $this->settings->coreEmail($sc);
        if ($recipient === '') {
            return;
        }
        $adminUrl = rtrim(self::appUrl(), '/') . '/admin#/sw/order/detail/' . $order->getId();
        $address = $order->getDeliveries()?->first()?->getShippingOrderAddress();
        $text = \sprintf(
            "Für Bestellung %s wurden „Versandkosten auf Anfrage“ gewählt.\nLieferort: %s %s %s\nWarenwert: %s %s\n\nBitte Versandkosten eintragen und den Kunden benachrichtigen:\n%s",
            $order->getOrderNumber(),
            $address?->getZipcode() ?? '',
            $address?->getCity() ?? '',
            $address?->getCountry()?->getIso() ?? '',
            number_format($order->getPositionPrice(), 2, ',', '.'),
            $order->getCurrency()?->getIsoCode() ?? '',
            $adminUrl
        );
        if (!empty(($order->getCustomFields() ?? [])[CF::ORDER_DIMS_MISSING]) && $this->settings->bool('showDimsMissing', $sc, true)) {
            $text .= "\n\nHinweis: Maße/Gewicht fehlen an mindestens einem Artikel.";
        }

        try {
            $this->mailService->send([
                'recipients' => [$recipient => $recipient],
                'salesChannelId' => $sc,
                'senderName' => 'Nova Versandkosten',
                'subject' => 'Versandkosten eintragen für Bestellung ' . $order->getOrderNumber(),
                'contentHtml' => nl2br(htmlspecialchars($text)),
                'contentPlain' => $text,
            ], $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Anfrage-E-Mail an den Shop fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Offene Anfrage-Bestellungen ohne Zahlung nach X Tagen (je Verkaufskanal, 0 = aus) stornieren.
     *
     * @return list<string> stornierte Bestellnummern
     */
    public function cancelDue(Context $context): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(o.id)) AS id, o.order_number, o.order_date_time, LOWER(HEX(o.sales_channel_id)) AS sales_channel_id,
                    os.technical_name AS order_state,
                    (SELECT ts.technical_name FROM order_transaction t
                       INNER JOIN state_machine_state ts ON ts.id = t.state_id
                      WHERE t.order_id = o.id AND t.order_version_id = o.version_id AND t.version_id = o.version_id
                      ORDER BY (t.id = o.primary_order_transaction_id) DESC, t.created_at DESC LIMIT 1) AS transaction_state
               FROM `order` o
               INNER JOIN state_machine_state os ON os.id = o.state_id
              WHERE o.version_id = :live AND os.technical_name IN (\'open\', \'in_progress\')
                AND JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, :path)) IN (\'true\', \'1\')',
            ['live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'path' => '$.' . CF::ORDER_REQUEST]
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cancelled = [];
        foreach ($rows as $row) {
            $days = $this->settings->int('requestCancelDays', (string) $row['sales_channel_id'], 14);
            try {
                $date = new \DateTimeImmutable((string) $row['order_date_time'], new \DateTimeZone('UTC'));
            } catch (\Exception) {
                continue;
            }
            $transactionState = \is_string($row['transaction_state'] ?? null) ? $row['transaction_state'] : null;
            if (!RequestOrder::isDueForCancellation($date, $now, $days, (string) $row['order_state'], $transactionState)) {
                continue;
            }
            try {
                $this->cancel((string) $row['id'], $context);
                $cancelled[] = (string) $row['order_number'];
            } catch (\Throwable $e) {
                $this->logger->warning('Nova Versandkosten: Anfrage-Bestellung konnte nicht storniert werden', ['order' => $row['order_number'], 'error' => $e->getMessage()]);
            }
        }

        return $cancelled;
    }

    private function cancel(string $orderId, Context $context): void
    {
        $comment = 'Nova Versandkosten: automatisch storniert (Versandkosten auf Anfrage, keine Zahlung)';
        $this->stateMachine->transition(new Transition('order', $orderId, 'cancel', 'stateId', $comment), $context);

        $order = $this->loadOrder($orderId, $context);
        foreach ($order?->getTransactions() ?? [] as $transaction) {
            if (\in_array($transaction->getStateMachineState()?->getTechnicalName(), self::OPEN_TRANSACTION_STATES, true)) {
                try {
                    $this->stateMachine->transition(new Transition('order_transaction', $transaction->getId(), 'cancel', 'stateId', $comment), $context);
                } catch (\Throwable) {
                }
            }
        }
        foreach ($order?->getDeliveries() ?? [] as $delivery) {
            if ($delivery->getStateMachineState()?->getTechnicalName() === 'open') {
                try {
                    $this->stateMachine->transition(new Transition('order_delivery', $delivery->getId(), 'cancel', 'stateId', $comment), $context);
                } catch (\Throwable) {
                }
            }
        }
    }

    public function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations([
            'stateMachineState',
            'currency',
            'orderCustomer.customer',
            'deliveries.stateMachineState',
            'deliveries.shippingMethod',
            'deliveries.shippingOrderAddress.country',
            'transactions.stateMachineState',
            'transactions.paymentMethod',
            'lineItems',
        ]);

        return $this->orders->search($criteria, $context)->getEntities()->first();
    }

    public static function mainDelivery(OrderEntity $order): ?OrderDeliveryEntity
    {
        $primaryId = $order->getPrimaryOrderDeliveryId();
        $best = null;
        foreach ($order->getDeliveries() ?? [] as $delivery) {
            if ($primaryId !== null && $delivery->getId() === $primaryId) {
                return $delivery;
            }
            if ($delivery->getShippingCosts()->getTotalPrice() < 0) {
                continue;
            }
            if ($best === null || $delivery->getShippingCosts()->getTotalPrice() > $best->getShippingCosts()->getTotalPrice()) {
                $best = $delivery;
            }
        }

        return $best;
    }

    private function updateOpenTransaction(OrderEntity $order, Context $versionContext): void
    {
        $transaction = $this->openTransaction($order);
        if ($transaction === null) {
            return;
        }
        $price = $order->getPrice();
        $this->transactions->update([[
            'id' => $transaction->getId(),
            'amount' => new CalculatedPrice($price->getTotalPrice(), $price->getTotalPrice(), $price->getCalculatedTaxes(), $price->getTaxRules()),
        ]], $versionContext);
    }

    private function openTransaction(OrderEntity $order): ?OrderTransactionEntity
    {
        $primaryId = $order->getPrimaryOrderTransactionId();
        $candidate = null;
        foreach ($order->getTransactions() ?? [] as $transaction) {
            if (!\in_array($transaction->getStateMachineState()?->getTechnicalName(), self::OPEN_TRANSACTION_STATES, true)) {
                continue;
            }
            if ($primaryId !== null && $transaction->getId() === $primaryId) {
                return $transaction;
            }
            if ($candidate === null || $transaction->getCreatedAt() > $candidate->getCreatedAt()) {
                $candidate = $transaction;
            }
        }

        return $candidate;
    }

    private function isGuestOrder(OrderEntity $order): bool
    {
        return (bool) $order->getOrderCustomer()?->getCustomer()?->getGuest()
            || ($order->getOrderCustomer()?->getCustomerId() === null && $order->getDeepLinkCode() !== null);
    }

    private function domainUrl(string $salesChannelId, string $languageId): string
    {
        $url = $this->connection->fetchOne(
            'SELECT url FROM sales_channel_domain WHERE sales_channel_id = :sc
              ORDER BY (language_id = :lang) DESC, (url LIKE \'https%\') DESC, created_at ASC LIMIT 1',
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'lang' => Uuid::fromHexToBytes($languageId)]
        );

        return \is_string($url) ? rtrim($url, '/') : rtrim(self::appUrl(), '/');
    }

    /**
     * @return array{id: string, sender_name: string, subject: string, content_html: string, content_plain: string}|null
     */
    private function mailTemplate(string $languageId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(mt.id)) AS id, tr.sender_name, tr.subject, tr.content_html, tr.content_plain
               FROM mail_template mt
               INNER JOIN mail_template_type ty ON ty.id = mt.mail_template_type_id
               INNER JOIN mail_template_translation tr ON tr.mail_template_id = mt.id
              WHERE ty.technical_name = :type AND tr.subject IS NOT NULL AND tr.content_html IS NOT NULL
              ORDER BY mt.system_default DESC, (tr.language_id = :lang) DESC, (tr.language_id = :system) DESC
              LIMIT 1',
            [
                'type' => MailTemplateInstaller::TYPE,
                'lang' => Uuid::fromHexToBytes($languageId),
                'system' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            ]
        );
        if ($row === false) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'sender_name' => (string) ($row['sender_name'] ?? ''),
            'subject' => (string) $row['subject'],
            'content_html' => (string) $row['content_html'],
            'content_plain' => (string) ($row['content_plain'] ?? strip_tags((string) $row['content_html'])),
        ];
    }

    public static function appUrl(): string
    {
        $url = $_SERVER['APP_URL'] ?? $_ENV['APP_URL'] ?? getenv('APP_URL');

        return \is_string($url) ? $url : '';
    }
}
