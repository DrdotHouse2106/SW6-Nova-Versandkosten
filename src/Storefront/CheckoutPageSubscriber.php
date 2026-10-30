<?php declare(strict_types=1);

namespace Nova\Versandkosten\Storefront;

use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Checkout\ShippingCostProcessor;
use Nova\Versandkosten\Installer\CustomFieldInstaller as CF;
use Nova\Versandkosten\Service\BeipackService;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Settings;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Storefront\Page\Page;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt an Warenkorb-, Bestätigungs- und Abschlussseite die Angaben für Hinweise, den Knopf
 * „Lieferung per Tour anfragen“ (mailto-Link), die Auswahl „Zu meiner Bestellung beipacken“ und den
 * Hinweis „Versandkosten werden nachberechnet“.
 */
class CheckoutPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly BeipackService $beipack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onConfirm',
            CheckoutCartPageLoadedEvent::class => 'onCart',
            CheckoutFinishPageLoadedEvent::class => 'onFinish',
        ];
    }

    public function onConfirm(CheckoutConfirmPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $context = $event->getSalesChannelContext();
        $this->extend($page, $page->getCart(), $context);

        $key = MethodMap::optionFor($context->getShippingMethod()->getTechnicalName());
        if ($key === OptionResult::BEIPACK) {
            $page->addExtension('novaVersandkostenBeipack', new ArrayStruct($this->beipackData($page->getCart(), $context)));
        }
    }

    public function onCart(CheckoutCartPageLoadedEvent $event): void
    {
        $this->extend($event->getPage(), $event->getPage()->getCart(), $event->getSalesChannelContext());
    }

    public function onFinish(CheckoutFinishPageLoadedEvent $event): void
    {
        $order = $event->getPage()->getOrder();
        $context = $event->getSalesChannelContext();
        $fields = $order->getCustomFields() ?? [];

        $beipackCode = null;
        if ($order->getDeepLinkCode() && empty($fields[CF::ORDER_REQUEST]) && empty($fields[CF::ORDER_BEIPACK_TO]) && $this->beipack->methodId($context->getSalesChannelId()) !== null) {
            $beipackCode = $order->getDeepLinkCode();
        }

        $event->getPage()->addExtension('novaVersandkosten', new ArrayStruct([
            'requestOrder' => !empty($fields[CF::ORDER_REQUEST]),
            'beipackTo' => \is_string($fields[CF::ORDER_BEIPACK_TO_NUMBER] ?? null) ? $fields[CF::ORDER_BEIPACK_TO_NUMBER] : null,
            'beipackDeepLinkCode' => $beipackCode,
        ]));
    }

    /**
     * Beipack-Auswahl: nur eigene (Kundenkonto) bzw. über Code/Nummer bestätigte Bestellungen.
     *
     * @return array<string, mixed>
     */
    private function beipackData(Cart $cart, SalesChannelContext $context): array
    {
        $customer = $context->getCustomer();
        $isCustomer = $customer !== null && !$customer->getGuest();
        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        $info = $data instanceof ArrayStruct ? $data->get('beipack') : null;
        $options = $data instanceof ArrayStruct ? $data->get('options') : null;
        $option = \is_array($options) && \is_array($options[OptionResult::BEIPACK] ?? null) ? $options[OptionResult::BEIPACK] : null;

        $selected = null;
        $selection = $this->beipack->selection($cart);
        if ($selection !== null) {
            [$row, $reason] = $this->beipack->validate($selection, $context);
            if ($row !== null && $reason === null) {
                $selected = BeipackService::summary($row);
            }
        }

        $factor = $context->getContext()->getCurrencyFactor();
        $meta = \is_array($option['meta'] ?? null) ? $option['meta'] : [];

        return [
            'isCustomer' => $isCustomer,
            'guestForm' => !$isCustomer && $this->settings->bool('beipackGuestForm', $context->getSalesChannelId(), true),
            'candidates' => $isCustomer ? array_map(BeipackService::summary(...), $this->beipack->candidates($context)) : [],
            'selected' => $selected,
            'state' => \is_array($info) ? (string) ($info['state'] ?? '') : '',
            'reason' => \is_array($info) ? (string) ($info['reason'] ?? '') : '',
            'combined' => isset($meta['combinedSale']) ? round((float) $meta['combinedSale'] * $factor, 2) : null,
            'paid' => isset($meta['paid']) ? round((float) $meta['paid'] * $factor, 2) : null,
            'price' => \is_array($option) && ($info['state'] ?? '') === 'ok' ? round((float) ($option['sale'] ?? 0) * $factor, 2) : null,
            'maxAgeDays' => $this->beipack->maxAgeDays($context->getSalesChannelId()),
        ];
    }

    private function extend(Page $page, Cart $cart, SalesChannelContext $context): void
    {
        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        if (!$data instanceof ArrayStruct) {
            return;
        }
        $sc = $context->getSalesChannelId();
        $options = (array) $data->get('options');
        $freight = \is_array($options['spedition'] ?? null) ? $options['spedition'] : [];
        $request = \is_array($options[OptionResult::REQUEST] ?? null) ? $options[OptionResult::REQUEST] : [];

        $page->addExtension('novaVersandkosten', new ArrayStruct([
            'mode' => $data->get('mode'),
            'freightOnRequest' => $data->get('mode') === 'freight' && empty($freight['available']) && empty($request['available']),
            'requestSelected' => MethodMap::optionFor($context->getShippingMethod()->getTechnicalName()) === OptionResult::REQUEST,
            'tourRequestUrl' => $this->settings->bool('showTourRequest', $sc, true) ? $this->mailto($cart, $context) : null,
        ]));
    }

    private function mailto(Cart $cart, SalesChannelContext $context): ?string
    {
        $sc = $context->getSalesChannelId();
        $recipient = $this->settings->string('requestEmail', $sc) ?: $this->settings->coreEmail($sc);
        if ($recipient === '') {
            return null;
        }

        $lines = [];
        foreach ($cart->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $item) {
            $number = (string) $item->getPayloadValue('productNumber');
            $lines[] = \sprintf('%d × %s%s', $item->getQuantity(), (string) $item->getLabel(), $number !== '' ? ' (' . $number . ')' : '');
        }
        $location = $context->getShippingLocation();
        $place = trim(($location->getAddress()?->getZipcode() ?? '') . ' ' . ($location->getAddress()?->getCity() ?? '') . ' ' . (string) $location->getCountry()->getIso());

        $body = "Guten Tag,\n\nbitte schicken Sie mir ein Angebot für die Lieferung per Tour.\n\nWarenkorb:\n"
            . implode("\n", $lines) . "\n\nLieferort: " . $place . "\n\nName:\nTelefon:\n";

        return 'mailto:' . rawurlencode($recipient)
            . '?subject=' . rawurlencode('Anfrage: Lieferung per Tour')
            . '&body=' . rawurlencode($body);
    }
}
