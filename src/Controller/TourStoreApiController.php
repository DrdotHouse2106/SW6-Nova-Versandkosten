<?php declare(strict_types=1);

namespace Nova\Versandkosten\Controller;

use Nova\Versandkosten\Service\Tour\TourService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Store-API (z. B. Headless/App) für „Lieferung mit unserer Tour“:
 *
 * - GET  /store-api/nova-versandkosten/tour         passende Termine (Datum, Preis) für Warenkorb + Lieferadresse
 * - POST /store-api/nova-versandkosten/tour/select  {"tourDateId": "…"} Termin am Warenkorb merken (leer = entfernen)
 *
 * Antwort nur mit Datum und Preis – nie Strecke oder Orte.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['store-api']])]
class TourStoreApiController
{
    public function __construct(
        private readonly TourService $tour,
        private readonly CartService $cartService,
    ) {
    }

    #[Route(path: '/store-api/nova-versandkosten/tour', name: 'store-api.nova_versandkosten.tour', methods: ['GET'])]
    public function options(SalesChannelContext $context): JsonResponse
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);

        return new JsonResponse($this->present($this->tour->evaluate($cart->getLineItems(), $context, $this->tour->selection($cart))));
    }

    #[Route(path: '/store-api/nova-versandkosten/tour/select', name: 'store-api.nova_versandkosten.tour.select', methods: ['POST'])]
    public function select(Request $request, SalesChannelContext $context): JsonResponse
    {
        $id = $request->request->get('tourDateId');
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $this->tour->select($cart, \is_string($id) && $id !== '' ? $id : null);
        $cart = $this->cartService->recalculate($cart, $context);

        return new JsonResponse($this->present($this->tour->evaluate($cart->getLineItems(), $context, $this->tour->selection($cart))));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function present(array $data): array
    {
        return [
            'available' => (bool) ($data['available'] ?? false),
            'state' => (string) ($data['state'] ?? ''),
            'selectedId' => \is_array($data['selected'] ?? null) ? $data['selected']['id'] : null,
            'options' => array_map(
                static fn (array $o) => ['id' => $o['id'], 'date' => $o['date'], 'label' => $o['label'], 'price' => $o['price'], 'orderDeadline' => $o['deadline']],
                \is_array($data['options'] ?? null) ? $data['options'] : []
            ),
        ];
    }
}
