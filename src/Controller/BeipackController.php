<?php declare(strict_types=1);

namespace Nova\Versandkosten\Controller;

use Nova\Versandkosten\Service\AttemptLimiter;
use Nova\Versandkosten\Service\BeipackService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront: Bestellung wählen, zu der beigepackt werden soll.
 *
 * - Link aus der Bestellbestätigung (Shopwares geheimer Bestell-Code) – für Gäste und Kunden,
 * - Auswahl aus den eigenen Bestellungen (Kundenkonto),
 * - Bestellnummer + E-Mail + PLZ (Versuchsbegrenzung 5 je Stunde und IP).
 *
 * Gespeichert wird nur die Bestell-ID am Warenkorb; angezeigt werden ausschließlich eigene bzw.
 * bestätigte Bestellungen. Fehlermeldungen verraten nicht, welche Angabe nicht passte.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
class BeipackController extends StorefrontController
{
    public function __construct(
        private readonly BeipackService $beipack,
        private readonly CartService $cartService,
        private readonly AbstractContextSwitchRoute $contextSwitch,
        private readonly AttemptLimiter $limiter,
        private readonly GenericPageLoaderInterface $pageLoader,
    ) {
    }

    /**
     * Link aus der Bestellbestätigung: prüft die Bestellung und zeigt nur eine Zwischenseite mit Knopf –
     * gemerkt und als Versandart vorgewählt wird erst per POST (seit 1.4.0; Link-Vorschauen und
     * Mail-Scanner ändern so weder Warenkorb noch Versandart).
     */
    #[Route(path: '/nova-versandkosten/beipacken/{deepLinkCode}', name: 'frontend.nova_versandkosten.beipack.link', requirements: ['deepLinkCode' => '[A-Za-z0-9_\-]{8,255}'], defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['GET'])]
    public function link(string $deepLinkCode, Request $request, SalesChannelContext $context): Response
    {
        $row = $this->linkedOrder($deepLinkCode, $context);
        if (!\is_array($row)) {
            return $row;
        }

        return $this->renderStorefront('@NovaVersandkosten/storefront/nova-versandkosten/confirm-action.html.twig', [
            'page' => $this->pageLoader->load($request, $context),
            'novaAction' => [
                'title' => 'novaVersandkosten.beipack.linkTitle',
                'text' => 'novaVersandkosten.beipack.linkText',
                'textParams' => ['%number%' => (string) $row['order_number']],
                'button' => 'novaVersandkosten.beipack.linkButton',
                'action' => $this->generateUrl('frontend.nova_versandkosten.beipack.link.save', ['deepLinkCode' => $deepLinkCode]),
            ],
        ]);
    }

    #[Route(path: '/nova-versandkosten/beipacken/{deepLinkCode}', name: 'frontend.nova_versandkosten.beipack.link.save', requirements: ['deepLinkCode' => '[A-Za-z0-9_\-]{8,255}'], defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['POST'])]
    public function linkSave(string $deepLinkCode, SalesChannelContext $context): Response
    {
        $row = $this->linkedOrder($deepLinkCode, $context);
        if (!\is_array($row)) {
            return $row;
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $this->beipack->select($cart, (string) $row['id'], 'code');
        $cart = $this->cartService->recalculate($cart, $context);

        $methodId = $this->beipack->methodId($context->getSalesChannelId());
        if ($methodId !== null) {
            $this->contextSwitch->switchContext(new RequestDataBag([SalesChannelContextService::SHIPPING_METHOD_ID => $methodId]), $context);
        }

        $this->addFlash(self::SUCCESS, $this->trans('novaVersandkosten.beipack.linked', ['%number%' => (string) $row['order_number']]));

        return $this->redirectToRoute($cart->getLineItems()->count() > 0 ? 'frontend.checkout.cart.page' : 'frontend.home.page');
    }

    /**
     * Bestellung zum Link prüfen; bei Fehler Hinweis + Weiterleitung zur Startseite.
     *
     * @return array<string, mixed>|Response
     */
    private function linkedOrder(string $deepLinkCode, SalesChannelContext $context): array|Response
    {
        $orderId = $this->beipack->findByDeepLinkCode($deepLinkCode, $context->getSalesChannelId());
        $row = $orderId === null ? null : $this->beipack->load($orderId);
        if ($row === null) {
            $this->addFlash(self::DANGER, $this->trans('novaVersandkosten.beipack.notFound'));

            return $this->redirectToRoute('frontend.home.page');
        }

        // Fremdes Kundenkonto angemeldet: nicht zulassen
        $customer = $context->getCustomer();
        if ($customer !== null && !$customer->getGuest() && !BeipackService::isOwnedBy($row, $context)) {
            $this->addFlash(self::DANGER, $this->trans('novaVersandkosten.beipack.notFound'));

            return $this->redirectToRoute('frontend.home.page');
        }

        $reason = $this->beipack->ineligibleReason($row, $context, false);
        if ($reason !== null) {
            $this->addFlash(self::DANGER, $this->trans('novaVersandkosten.beipack.notPossible', ['%number%' => (string) $row['order_number']]));

            return $this->redirectToRoute('frontend.home.page');
        }

        return $row;
    }

    #[Route(path: '/nova-versandkosten/beipacken-auswahl', name: 'frontend.nova_versandkosten.beipack.select', defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST => true], methods: ['POST'])]
    public function select(Request $request, SalesChannelContext $context): Response
    {
        $orderId = (string) $request->request->get('orderId', '');
        $cart = $this->cartService->getCart($context->getToken(), $context);

        if ($orderId === '') {
            $this->beipack->select($cart, null, '');
            $this->cartService->recalculate($cart, $context);

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        $row = $this->beipack->load($orderId);
        if ($row === null || !BeipackService::isOwnedBy($row, $context) || $this->beipack->ineligibleReason($row, $context) !== null) {
            $this->addFlash(self::DANGER, $this->trans('novaVersandkosten.beipack.notFound'));

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        $this->beipack->select($cart, (string) $row['id'], 'customer');
        $this->cartService->recalculate($cart, $context);

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }

    #[Route(path: '/nova-versandkosten/beipacken-pruefen', name: 'frontend.nova_versandkosten.beipack.verify', defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST => true], methods: ['POST'])]
    public function verify(Request $request, SalesChannelContext $context): Response
    {
        if (!$this->limiter->attempt('beipack', (string) $request->getClientIp())) {
            $this->addFlash(self::DANGER, $this->trans('novaVersandkosten.beipack.tooManyAttempts'));

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        $orderId = $this->beipack->findByNumber(
            (string) $request->request->get('orderNumber', ''),
            (string) $request->request->get('email', ''),
            (string) $request->request->get('zipcode', ''),
            $context->getSalesChannelId()
        );
        $row = $orderId === null ? null : $this->beipack->load($orderId);
        if ($row === null) {
            $this->addFlash(self::DANGER, $this->trans('novaVersandkosten.beipack.notFound'));

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }
        if ($this->beipack->ineligibleReason($row, $context) !== null) {
            $this->addFlash(self::DANGER, $this->trans('novaVersandkosten.beipack.notPossible', ['%number%' => (string) $row['order_number']]));

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $this->beipack->select($cart, (string) $row['id'], 'code');
        $this->cartService->recalculate($cart, $context);
        $this->addFlash(self::SUCCESS, $this->trans('novaVersandkosten.beipack.selected', ['%number%' => (string) $row['order_number']]));

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }
}
