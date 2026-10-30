<?php declare(strict_types=1);

namespace Nova\Versandkosten\Storefront;

use Nova\Versandkosten\Checkout\ShippingCostProcessor;
use Nova\Versandkosten\Service\Settings;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Page;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt an Warenkorb- und Bestätigungsseite die Angaben für Hinweise und den Knopf
 * „Lieferung per Tour anfragen“ (mailto-Link mit vorbefülltem Warenkorbinhalt).
 */
class CheckoutPageSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onConfirm',
            CheckoutCartPageLoadedEvent::class => 'onCart',
        ];
    }

    public function onConfirm(CheckoutConfirmPageLoadedEvent $event): void
    {
        $this->extend($event->getPage(), $event->getPage()->getCart(), $event->getSalesChannelContext());
    }

    public function onCart(CheckoutCartPageLoadedEvent $event): void
    {
        $this->extend($event->getPage(), $event->getPage()->getCart(), $event->getSalesChannelContext());
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

        $page->addExtension('novaVersandkosten', new ArrayStruct([
            'mode' => $data->get('mode'),
            'freightOnRequest' => $data->get('mode') === 'freight' && empty($freight['available']),
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
