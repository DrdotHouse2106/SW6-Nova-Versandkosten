<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Calculator\RequestOrder;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Settings;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\Checkout\Gateway\SalesChannel\CheckoutGatewayRouteResponse;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * „Versandkosten auf Anfrage“: In der Kasse nur Zahlarten anbieten, die beim Bestellen kein Geld
 * einziehen (Einstellung; leer = Vorkasse). Ist eine andere gewählt, sperrt Shopware sie und wechselt.
 *
 * Bestehende Bestellungen (Zahlungsart ändern / „Zahlung nachholen“) sind ausgenommen – dort zeigt
 * Shopware alle Zahlarten mit „Nach Bestellabschluss änderbar“, damit der Kunde nach dem Eintragen der
 * Versandkosten z. B. per PayPal bezahlen kann.
 */
class CheckoutGatewayRouteDecorator extends AbstractCheckoutGatewayRoute
{
    public function __construct(
        private readonly AbstractCheckoutGatewayRoute $decorated,
        private readonly Settings $settings,
    ) {
    }

    public function getDecorated(): AbstractCheckoutGatewayRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, Cart $cart, SalesChannelContext $context): CheckoutGatewayRouteResponse
    {
        $response = $this->decorated->load($request, $cart, $context);
        if ($cart->hasExtension(OrderConverter::ORIGINAL_ID) || !self::isRequestCart($cart)) {
            return $response;
        }

        $methods = $response->getPaymentMethods();
        $handlers = [];
        foreach ($methods as $method) {
            $handlers[$method->getId()] = $method->getHandlerIdentifier();
        }
        $allowed = RequestOrder::allowedPaymentMethods($handlers, $this->settings->ids('requestPaymentMethodIds', $context->getSalesChannelId()));

        foreach ($methods->getIds() as $id) {
            if (!\in_array(strtolower((string) $id), $allowed, true)) {
                $methods->remove((string) $id);
            }
        }

        $current = $context->getPaymentMethod();
        if (!\in_array(strtolower($current->getId()), $allowed, true)) {
            $response->getErrors()->add(new PaymentMethodBlockedError(
                (string) ($current->getTranslation('name') ?? $current->getName() ?? ''),
                'Bei „Versandkosten auf Anfrage“ nicht möglich',
                $current->getId(),
            ));
        }

        return $response;
    }

    public static function isRequestCart(Cart $cart): bool
    {
        foreach ($cart->getDeliveries() as $delivery) {
            if (MethodMap::optionFor($delivery->getShippingMethod()->getTechnicalName()) === OptionResult::REQUEST) {
                return true;
            }
        }

        return false;
    }
}
