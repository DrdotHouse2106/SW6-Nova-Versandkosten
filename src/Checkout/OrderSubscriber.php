<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\Beipack;
use Nova\Versandkosten\Calculator\DeliveryEstimator;
use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Calculator\RequestOrder;
use Nova\Versandkosten\Service\BeipackService;
use Nova\Versandkosten\Service\RequestOrderService;
use Nova\Versandkosten\Installer\CustomFieldInstaller as CF;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Settings;
use Nova\Versandkosten\Service\Tour\TourRepository;
use Nova\Versandkosten\Service\Tour\TourService;
use Nova\Versandkosten\Calculator\Tour\TourText;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Nova\Versandkosten\Service\OrderCostRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Speichert das Ergebnis der Versandberechnung an der Bestellung (order.customFields: Dienstleister,
 * Pakete mit Maßen/Gewicht, Verkauf, „Maße fehlen“ – Grundlage für Warenwirtschaft und Etikettendruck)
 * und den Einkauf in der eigenen Tabelle nova_vk_order_cost (nicht über die Store-API sichtbar); schickt
 * auf Wunsch eine E-Mail bei negativer Versandmarge.
 *
 * Beipacken: Bestellkommentar „BEIPACK zu Bestellung <Nr>“ vorne, Lieferadresse der alten Bestellung,
 * Verweise an beiden Bestellungen, Hinweis im internen Kommentar der alten Bestellung.
 * Versandkosten auf Anfrage: Kennzeichen an der Bestellung, E-Mail an den Shop.
 * Eigene Tour: Kommentar „TOUR: <Wochentag, Datum>“ vorne, Zusatzfelder (Termin, Variante, Abweichung,
 * Einordnung); nach dem Anlegen legt die erste Buchung die Variante des Termins fest.
 *
 * Bei der Nachberechnung einer bestehenden Bestellung (Administration) bleibt alles unverändert.
 */
class OrderSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** Paketangaben in order.customFields (Warenwirtschaft, Lieferschein) – Einkauf liegt in nova_vk_order_cost */
    public const PUBLIC_PACKAGE_KEYS = ['weightKg', 'lengthCm', 'widthCm', 'heightCm', 'bulky', 'tier', 'sale'];

    /** @var array<string, array{purchaseNet: float, saleNet: float, data: array<string, mixed>}> Bestell-ID → Einkauf */
    private array $pendingCosts = [];

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orders
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly AbstractMailService $mailService,
        private readonly LoggerInterface $logger,
        private readonly BeipackService $beipack,
        private readonly RequestOrderService $requests,
        private readonly EntityRepository $orders,
        private readonly TourRepository $tours,
        private readonly InternalQuoteStore $internal,
        private readonly OrderCostRepository $costs,
    ) {
    }

    public function reset(): void
    {
        $this->pendingCosts = [];
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
        if ($cart->hasExtension(OrderConverter::ORIGINAL_ID)) {
            return;
        }
        $public = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        $delivery = $cart->getDeliveries()->first();
        if ($delivery === null) {
            return;
        }
        $key = MethodMap::optionFor($delivery->getShippingMethod()->getTechnicalName());
        // Vollständiges Ergebnis aus der Anfrage (Shopware rechnet beim Bestellen neu); fehlt es, nur Anzeige-Daten.
        // Die Tour hängt nicht an den Tarifen und wird auch ohne Rechenergebnis gespeichert.
        $full = $this->internal->get($cart->getToken()) ?? ($public instanceof ArrayStruct ? $public->all() : null);
        if ($key !== OptionResult::TOUR && ($full === null || !empty($full['noTariffs']))) {
            return;
        }
        $data = new ArrayStruct($full ?? []);
        $options = $data->get('options');
        $option = $key !== null && \is_array($options) ? ($options[$key] ?? null) : null;

        $fields = [
            CF::ORDER_DIMS_MISSING => (bool) $data->get('dimsMissing'),
        ];
        $cost = null;
        if (\is_array($option)) {
            $rawPackages = array_values(array_filter((array) ($option['packages'] ?? []), 'is_array'));
            // Für Warenwirtschaft/Lieferschein: Maße, Gewicht, Einordnung – ohne Einkauf
            $packages = array_map(static fn (array $p) => array_intersect_key($p, array_flip(self::PUBLIC_PACKAGE_KEYS)), $rawPackages);
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
                CF::ORDER_SALE => $sale,
                CF::ORDER_SALE_NET => $saleNet,
                CF::ORDER_DELIVERY => $deliveryText,
                CF::ORDER_DETAILS => [
                    'mode' => $data->get('mode'),
                    'goodsValue' => $data->get('goodsValue'),
                    'free' => $data->get('free'),
                    'vatIncluded' => (bool) ($option['vatIncluded'] ?? true),
                    'hints' => $option['hints'] ?? [],
                ],
            ];
            // Einkauf nur in der eigenen Tabelle (nicht Store-API-sichtbar), siehe OrderCostRepository
            $cost = [
                'purchaseNet' => (float) ($option['purchaseNet'] ?? 0),
                'saleNet' => $saleNet,
                'data' => [
                    'option' => $key,
                    'productId' => (string) ($option['productId'] ?? ''),
                    'productName' => (string) ($option['productName'] ?? ''),
                    'packages' => array_map(static fn (array $p) => array_intersect_key($p, array_flip(['purchaseNet', 'sale', 'tier', 'lines'])), $rawPackages),
                    'freightQuotationId' => $key === OptionResult::FREIGHT ? ($option['productId'] ?? null) : null,
                ],
            ];
        }

        $converted = $event->getConvertedCart();

        if ($key === OptionResult::BEIPACK) {
            $converted = $this->convertBeipack($converted, $fields, $data, \is_array($option) ? $option : []);
        }
        if ($key === OptionResult::TOUR) {
            $tour = $this->internal->getTour($cart->getToken());
            $converted = $this->convertTour($converted, $fields, $tour !== null ? new ArrayStruct($tour) : $cart->getExtension(TourService::EXTENSION), $event->getSalesChannelContext()->getSalesChannelId());
            $cost = isset($fields[CF::ORDER_SALE_NET]) ? ['purchaseNet' => 0.0, 'saleNet' => (float) $fields[CF::ORDER_SALE_NET], 'data' => ['option' => OptionResult::TOUR]] : $cost;
        }
        if ($key === OptionResult::REQUEST) {
            $fields[CF::ORDER_REQUEST] = true;
            $fields[CF::ORDER_REQUEST_STATUS] = RequestOrder::STATUS_OPEN;
        }

        $converted['customFields'] = array_merge(\is_array($converted['customFields'] ?? null) ? $converted['customFields'] : [], $fields);
        if ($cost !== null && \is_string($converted['id'] ?? null)) {
            $this->pendingCosts[strtolower($converted['id'])] = $cost;
        }
        $event->setConvertedCart($converted);
    }

    /**
     * @param array<string, mixed> $converted
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $option
     *
     * @return array<string, mixed>
     */
    private function convertBeipack(array $converted, array &$fields, ArrayStruct $data, array $option): array
    {
        $info = $data->get('beipack');
        $orderId = \is_array($info) && \is_string($info['orderId'] ?? null) ? $info['orderId'] : null;
        $row = $orderId === null ? null : $this->beipack->load($orderId);
        if ($row === null) {
            return $converted;
        }
        $number = (string) $row['order_number'];

        $converted['customerComment'] = Beipack::comment($number, \is_string($converted['customerComment'] ?? null) ? $converted['customerComment'] : null);
        $fields[CF::ORDER_BEIPACK_TO] = (string) $row['id'];
        $fields[CF::ORDER_BEIPACK_TO_NUMBER] = $number;
        $meta = \is_array($option['meta'] ?? null) ? $option['meta'] : [];
        if (\is_string($meta['basedOn'] ?? null)) {
            $fields[CF::ORDER_OPTION] = $meta['basedOn'];
        }
        $details = \is_array($fields[CF::ORDER_DETAILS] ?? null) ? $fields[CF::ORDER_DETAILS] : [];
        $fields[CF::ORDER_DETAILS] = $details + ['beipack' => ['orderNumber' => $number, 'combinedSale' => $meta['combinedSale'] ?? null, 'paid' => $meta['paid'] ?? null]];

        // Das Paket geht an die Adresse der alten Bestellung – deren Lieferadresse übernehmen
        $address = BeipackService::shippingAddress($row);
        if ($address !== [] && isset($converted['deliveries'][0]) && \is_array($converted['deliveries'][0])) {
            $previous = $converted['deliveries'][0]['shippingOrderAddress'] ?? null;
            // Rechnungsadresse zeigte auf dieselbe Adresse: als eigene Bestelladresse erhalten
            if (\is_array($previous) && ($previous['id'] ?? null) === ($converted['billingAddressId'] ?? null)) {
                $converted['addresses'] = array_merge(\is_array($converted['addresses'] ?? null) ? $converted['addresses'] : [], [$previous]);
            }
            unset($converted['deliveries'][0]['shippingOrderAddressId'], $converted['deliveries'][0]['shippingOrderAddressVersionId']);
            $converted['deliveries'][0]['shippingOrderAddress'] = $address;
        }

        return $converted;
    }

    /**
     * @param array<string, mixed> $converted
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function convertTour(array $converted, array &$fields, mixed $extension, string $salesChannelId): array
    {
        $data = $extension instanceof ArrayStruct ? $extension->all() : [];
        $selected = \is_array($data['selected'] ?? null) ? $data['selected'] : null;
        if ($selected === null || ($data['state'] ?? '') !== 'ok') {
            return $converted;
        }
        $date = new \DateTimeImmutable((string) $selected['date']);
        $vat = $this->settings->rules($salesChannelId)->vatPercent;
        $sale = (int) $selected['priceCents'] / 100;
        $variantId = \is_string($selected['variantId'] ?? null) ? $selected['variantId'] : null;

        $fields[CF::ORDER_OPTION] = OptionResult::TOUR;
        $fields[CF::ORDER_CARRIER] = 'Eigene Tour';
        $fields[CF::ORDER_PRODUCT] = 'Tour ' . TourText::date($date);
        $fields[CF::ORDER_PACKAGES] = [];
        $fields[CF::ORDER_PACKAGE_COUNT] = 0;
        $fields[CF::ORDER_SALE] = $sale;
        $fields[CF::ORDER_SALE_NET] = round($sale / (1 + $vat / 100), 2);
        $fields[CF::ORDER_DELIVERY] = TourText::date($date);
        $fields[CF::ORDER_TOUR_DATE_ID] = (string) $selected['id'];
        $fields[CF::ORDER_TOUR_DATE] = $date->format('Y-m-d');
        $fields[CF::ORDER_TOUR_VARIANT] = $variantId;
        $fields[CF::ORDER_TOUR_VARIANT_NAME] = $this->tours->variantName($variantId);
        $fields[CF::ORDER_TOUR_DEVIATION] = isset($selected['deviationKm']) ? (float) $selected['deviationKm'] : null;
        $fields[CF::ORDER_TOUR_TIER] = (string) ($data['tier'] ?? '');

        $converted['customerComment'] = TourText::comment($date, \is_string($converted['customerComment'] ?? null) ? $converted['customerComment'] : null);

        return $converted;
    }

    /**
     * Erste Buchung legt die Variante fest. War der Termin inzwischen auf eine andere Variante festgelegt
     * (gleichzeitige Bestellungen), bekommt die Bestellung einen internen Hinweis zur Prüfung.
     */
    private function fixTourVariant(OrderEntity $order, string $dateId, string $variantId, Context $context): void
    {
        try {
            if ($this->tours->fixVariant($dateId, $variantId)) {
                return;
            }
            $note = 'TOUR: Der Termin war beim Bestellen bereits auf eine andere Variante festgelegt – Lieferadresse bitte prüfen.';
            $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($order, $note): void {
                $this->orders->update([[
                    'id' => $order->getId(),
                    'internalComment' => trim($note . "\n" . (string) $order->getInternalComment()),
                ]], $context);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Tour-Variante konnte nicht festgelegt werden', ['error' => $e->getMessage()]);
        }
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $fields = $order->getCustomFields() ?? [];
        $cost = $this->pendingCosts[strtolower($order->getId())] ?? null;
        unset($this->pendingCosts[strtolower($order->getId())]);
        if ($cost !== null) {
            try {
                $this->costs->save($order->getId(), $cost['purchaseNet'], $cost['data']);
            } catch (\Throwable $e) {
                $this->logger->warning('Nova Versandkosten: Einkauf der Bestellung konnte nicht gespeichert werden', ['order' => $order->getOrderNumber(), 'error' => $e->getMessage()]);
            }
        }
        if (\is_string($fields[CF::ORDER_TOUR_DATE_ID] ?? null) && \is_string($fields[CF::ORDER_TOUR_VARIANT] ?? null)) {
            $this->fixTourVariant($order, $fields[CF::ORDER_TOUR_DATE_ID], $fields[CF::ORDER_TOUR_VARIANT], $event->getContext());
        }
        if (\is_string($fields[CF::ORDER_BEIPACK_TO] ?? null)) {
            $this->linkBeipack($order, $fields[CF::ORDER_BEIPACK_TO], $event->getContext());
        }
        if (!empty($fields[CF::ORDER_REQUEST])) {
            $this->requests->notifyShop($order, $event->getContext());
        }

        $this->marginMail($event, $cost);
    }

    /**
     * Alte Bestellung: Verweis-Liste (Zusatzfeld) und Hinweis im internen Kommentar.
     */
    private function linkBeipack(OrderEntity $order, string $oldOrderId, Context $context): void
    {
        $row = $this->beipack->load($oldOrderId);
        if ($row === null) {
            return;
        }
        $list = \is_array($row['customFields'][CF::ORDER_BEIPACK_ORDERS] ?? null) ? array_values($row['customFields'][CF::ORDER_BEIPACK_ORDERS]) : [];
        $list[] = ['id' => $order->getId(), 'orderNumber' => $order->getOrderNumber(), 'date' => $order->getOrderDateTime()->format(\DATE_ATOM)];

        $note = \sprintf('BEIPACK: Bestellung %s (%s) wird zu dieser Bestellung beigepackt – gemeinsam versenden.', (string) $order->getOrderNumber(), $order->getOrderDateTime()->format('d.m.Y H:i'));
        $comment = $this->beipackComment($oldOrderId);

        try {
            $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($oldOrderId, $list, $note, $comment): void {
                $this->orders->update([[
                    'id' => $oldOrderId,
                    'customFields' => [CF::ORDER_BEIPACK_ORDERS => $list],
                    'internalComment' => trim($note . ($comment !== '' ? "\n" . $comment : '')),
                ]], $context);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Beipack-Hinweis an der alten Bestellung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    private function beipackComment(string $orderId): string
    {
        $value = $this->beipackInternalComment($orderId);

        return \is_string($value) ? trim($value) : '';
    }

    private function beipackInternalComment(string $orderId): mixed
    {
        try {
            $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([$orderId]);
            $order = $this->orders->search($criteria, Context::createDefaultContext())->getEntities()->first();

            return $order instanceof OrderEntity ? $order->getInternalComment() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{purchaseNet: float, saleNet: float, data: array<string, mixed>}|null $cost
     */
    private function marginMail(CheckoutOrderPlacedEvent $event, ?array $cost): void
    {
        $salesChannelId = $event->getSalesChannelId();
        if ($cost === null || !$this->settings->bool('marginMailEnabled', $salesChannelId)) {
            return;
        }
        $order = $event->getOrder();
        $fields = $order->getCustomFields() ?? [];
        $purchase = $cost['purchaseNet'];
        $saleNet = $cost['saleNet'];
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
        if (!empty($fields[CF::ORDER_DIMS_MISSING]) && $this->settings->bool('showDimsMissing', $salesChannelId, true)) {
            $text .= "\nHinweis: Maße/Gewicht fehlen an mindestens einem Artikel (Standardwerte verwendet).";
        }

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
