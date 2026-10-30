<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Settings;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\SalesChannel\ShippingMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Blendet Plugin-Versandarten aus, die für den aktuellen Warenkorb nicht passen (Gewicht, Maße,
 * Warenwert, Land, Speditionsware …), und hängt an die übrigen Preis, Zustelldatum und Hinweise
 * (Erweiterung „novaVersandkosten“ an der Versandart, für Storefront und Store-API).
 *
 * Fremde Versandarten (z. B. „Abholung auf Treffen“ aus Nova Termine) bleiben unberührt.
 */
class ShippingMethodRouteDecorator extends AbstractShippingMethodRoute
{
    public function __construct(
        private readonly AbstractShippingMethodRoute $decorated,
        private readonly CartService $cartService,
        private readonly Settings $settings,
    ) {
    }

    public function getDecorated(): AbstractShippingMethodRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ShippingMethodRouteResponse
    {
        $response = $this->decorated->load($request, $context, $criteria);
        $methods = $response->getShippingMethods();

        $ours = false;
        foreach ($methods as $method) {
            if (MethodMap::optionFor($method->getTechnicalName()) !== null) {
                $ours = true;
                break;
            }
        }
        if (!$ours) {
            return $response;
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        if (!$data instanceof ArrayStruct) {
            // Keine Tarife importiert oder leerer Warenkorb: Shopware-Verhalten unverändert
            return $response;
        }
        $options = $data->get('options');
        if (!\is_array($options)) {
            return $response;
        }

        $showEstimate = $this->settings->bool('showEstimate', $context->getSalesChannelId(), true);
        foreach ($methods->getElements() as $id => $method) {
            $key = MethodMap::optionFor($method->getTechnicalName());
            if ($key === null) {
                continue;
            }
            $option = $options[$key] ?? null;
            if (!\is_array($option) || empty($option['available'])) {
                $methods->remove((string) $id);
                continue;
            }
            $method->addExtension(ShippingCostProcessor::EXTENSION, new ArrayStruct(OptionPresenter::present($option, $context, $showEstimate)));
        }

        return $response;
    }
}
