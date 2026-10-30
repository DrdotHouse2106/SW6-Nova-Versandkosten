<?php declare(strict_types=1);

namespace Nova\Versandkosten\Controller;

use Nova\Versandkosten\Service\Tour\TourNotificationService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Store-API: Tour-Benachrichtigung im Kundenkonto (angemeldeter Kunde, kein Gast).
 *
 * - GET  /store-api/nova-versandkosten/tour-notification  Lieferadressen mit Schalterstellung
 * - POST /store-api/nova-versandkosten/tour-notification  {"addressId": "…", "subscribe": true|false}
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['store-api'], PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true])]
class TourNotificationStoreApiController
{
    public function __construct(private readonly TourNotificationService $notifications)
    {
    }

    #[Route(path: '/store-api/nova-versandkosten/tour-notification', name: 'store-api.nova_versandkosten.tour_notification', methods: ['GET'])]
    public function load(SalesChannelContext $context, CustomerEntity $customer): JsonResponse
    {
        if (!$this->notifications->enabled($context->getSalesChannelId()) || $customer->getGuest()) {
            return new JsonResponse(['enabled' => false, 'addresses' => []]);
        }

        return new JsonResponse(['enabled' => true, 'addresses' => $this->notifications->accountAddresses($customer->getId(), $context->getSalesChannelId())]);
    }

    #[Route(path: '/store-api/nova-versandkosten/tour-notification', name: 'store-api.nova_versandkosten.tour_notification.save', methods: ['POST'])]
    public function save(Request $request, SalesChannelContext $context, CustomerEntity $customer): JsonResponse
    {
        if (!$this->notifications->enabled($context->getSalesChannelId()) || $customer->getGuest()) {
            return new JsonResponse(['errors' => [['code' => 'NOVA_VK_TOUR_NOTIFY_DISABLED', 'detail' => 'Tour-Benachrichtigung ist nicht aktiv.']]], 400);
        }
        $ok = $this->notifications->setAccountSubscription(
            $customer->getId(),
            (string) $request->request->get('addressId', ''),
            $request->request->getBoolean('subscribe'),
            $context->getSalesChannelId(),
            $context->getLanguageId()
        );
        if (!$ok) {
            return new JsonResponse(['errors' => [['code' => 'NOVA_VK_TOUR_NOTIFY_ADDRESS', 'detail' => 'Adresse nicht gefunden oder Postleitzahl nicht im Liefergebiet.']]], 400);
        }

        return new JsonResponse(['enabled' => true, 'addresses' => $this->notifications->accountAddresses($customer->getId(), $context->getSalesChannelId())]);
    }
}
