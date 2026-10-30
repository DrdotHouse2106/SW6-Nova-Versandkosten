<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\DeliveryEstimator;
use Nova\Versandkosten\Installer\CustomFieldInstaller as CF;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Settings;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Speichert das Ergebnis der Versandberechnung an der Bestellung (order.customFields) – Grundlage für
 * Warenwirtschaft/ERPNext und Etikettendruck – und schickt auf Wunsch eine E-Mail bei negativer
 * Versandmarge.
 */
class OrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AbstractMailService $mailService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $cart = $event->getCart();
        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        $delivery = $cart->getDeliveries()->first();
        if (!$data instanceof ArrayStruct || $delivery === null) {
            return;
        }

        $key = MethodMap::optionFor($delivery->getShippingMethod()->getTechnicalName());
        $options = $data->get('options');
        $option = $key !== null && \is_array($options) ? ($options[$key] ?? null) : null;

        $fields = [
            CF::ORDER_DIMS_MISSING => (bool) $data->get('dimsMissing'),
        ];
        if (\is_array($option)) {
            $packages = array_map(static fn ($p) => \is_array($p) ? array_intersect_key($p, array_flip(['weightKg', 'lengthCm', 'widthCm', 'heightCm', 'bulky', 'tier', 'purchaseNet', 'sale'])) : [], (array) ($option['packages'] ?? []));
            $vat = $this->settings->rules($event->getSalesChannelContext()->getSalesChannelId())->vatPercent;
            $sale = (float) ($option['sale'] ?? 0);
            $saleNet = !empty($option['vatIncluded']) ? round($sale / (1 + $vat / 100), 2) : $sale;
            $deliveryText = null;
            if (\is_string($option['deliveryFrom'] ?? null) && \is_string($option['deliveryTo'] ?? null)) {
                $deliveryText = DeliveryEstimator::formatRange(new \DateTimeImmutable($option['deliveryFrom']), new \DateTimeImmutable($option['deliveryTo']));
            }

            $fields += [
                CF::ORDER_OPTION => $key,
                CF::ORDER_CARRIER => (string) ($option['carrierName'] ?? ''),
                CF::ORDER_PRODUCT => (string) ($option['productName'] ?? ''),
                CF::ORDER_PACKAGES => $packages,
                CF::ORDER_PACKAGE_COUNT => \count($packages),
                CF::ORDER_PURCHASE => (float) ($option['purchaseNet'] ?? 0),
                CF::ORDER_SALE => $sale,
                CF::ORDER_SALE_NET => $saleNet,
                CF::ORDER_DELIVERY => $deliveryText,
                CF::ORDER_DETAILS => [
                    'mode' => $data->get('mode'),
                    'goodsValue' => $data->get('goodsValue'),
                    'free' => $data->get('free'),
                    'vatIncluded' => (bool) ($option['vatIncluded'] ?? true),
                    'hints' => $option['hints'] ?? [],
                    'freightQuotationId' => $key === 'spedition' ? ($option['productId'] ?? null) : null,
                ],
            ];
        }

        $converted = $event->getConvertedCart();
        $converted['customFields'] = array_merge(\is_array($converted['customFields'] ?? null) ? $converted['customFields'] : [], $fields);
        $event->setConvertedCart($converted);
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelId();
        if (!$this->settings->bool('marginMailEnabled', $salesChannelId)) {
            return;
        }
        $order = $event->getOrder();
        $fields = $order->getCustomFields() ?? [];
        if (!isset($fields[CF::ORDER_PURCHASE], $fields[CF::ORDER_SALE_NET])) {
            return;
        }
        $purchase = (float) $fields[CF::ORDER_PURCHASE];
        $saleNet = (float) $fields[CF::ORDER_SALE_NET];
        if ($saleNet >= $purchase || $purchase <= 0) {
            return;
        }

        $recipient = $this->settings->string('marginMailRecipient', $salesChannelId);
        if ($recipient === '') {
            $recipient = $this->settings->coreEmail($salesChannelId);
        }
        if ($recipient === '') {
            return;
        }

        $text = \sprintf(
            "Bestellung %s: Versandkosten netto %s € liegen unter dem geschätzten Einkauf %s € (Differenz %s €).\nVersandart: %s %s, Pakete: %d.",
            $order->getOrderNumber(),
            number_format($saleNet, 2, ',', '.'),
            number_format($purchase, 2, ',', '.'),
            number_format($saleNet - $purchase, 2, ',', '.'),
            (string) ($fields[CF::ORDER_CARRIER] ?? ''),
            (string) ($fields[CF::ORDER_PRODUCT] ?? ''),
            (int) ($fields[CF::ORDER_PACKAGE_COUNT] ?? 0),
        );

        try {
            $this->mailService->send([
                'recipients' => [$recipient => $recipient],
                'salesChannelId' => $salesChannelId,
                'senderName' => 'Nova Versandkosten',
                'subject' => 'Negative Versandmarge: Bestellung ' . $order->getOrderNumber(),
                'contentHtml' => nl2br(htmlspecialchars($text)),
                'contentPlain' => $text,
            ], $event->getContext());
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Margen-E-Mail fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }
}
