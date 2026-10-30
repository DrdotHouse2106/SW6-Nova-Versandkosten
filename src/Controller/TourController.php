<?php declare(strict_types=1);

namespace Nova\Versandkosten\Controller;

use Nova\Versandkosten\Service\Tour\TourService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront: Tourtermin in der Kasse wählen. Gespeichert wird nur die Termin-ID am Warenkorb; ob der
 * Termin passt, prüft das Plugin bei jeder Berechnung und beim Bestellen erneut.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
class TourController extends StorefrontController
{
    public function __construct(
        private readonly TourService $tour,
        private readonly CartService $cartService,
    ) {
    }

    #[Route(path: '/nova-versandkosten/tour-termin', name: 'frontend.nova_versandkosten.tour.select', methods: ['POST'])]
    public function select(Request $request, SalesChannelContext $context): Response
    {
        $id = (string) $request->request->get('tourDateId', '');
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $this->tour->select($cart, $id !== '' ? $id : null);
        $this->cartService->recalculate($cart, $context);

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }
}
