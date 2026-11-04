<?php declare(strict_types=1);

namespace Nova\Versandkosten\Controller;

use Nova\Versandkosten\Service\Tour\TourNotificationService;
use Nova\Versandkosten\Service\Tour\TourNotifyLimiter;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront: Tour-Benachrichtigung.
 *
 * - Formular für Gäste/Besucher (Erlebniswelten-Element oder eigener Einbau): E-Mail + PLZ + Einwilligung →
 *   Double-Opt-in-Mail. Schutz: Shopware-Captchas (Route-Attribut _captcha wie beim Kontaktformular, damit
 *   auch Nova Captcha greift), Honeypot, Herkunftsprüfung (Origin/Referer), RateLimiter je IP und Kanal.
 *   Antwortformat wie beim Kontaktformular (FormCmsHandler).
 * - Links aus den Mails: bestätigen, abmelden, weiter benachrichtigen (Zwischenseite, Aktion erst per POST).
 * - Kundenkonto: Schalter je Lieferadresse (Seite „Tour-Benachrichtigung“).
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
class TourNotificationController extends StorefrontController
{
    public function __construct(
        private readonly TourNotificationService $notifications,
        private readonly TourNotifyLimiter $limiter,
        private readonly GenericPageLoaderInterface $pageLoader,
    ) {
    }

    #[Route(
        path: '/nova-versandkosten/tour-benachrichtigung',
        name: 'frontend.nova_versandkosten.tour_notify.subscribe',
        defaults: ['XmlHttpRequest' => true, PlatformRequest::ATTRIBUTE_CAPTCHA => true],
        methods: ['POST']
    )]
    public function subscribe(Request $request, SalesChannelContext $context): JsonResponse
    {
        $salesChannelId = $context->getSalesChannelId();
        if (!$this->notifications->enabled($salesChannelId) || !self::isSameOrigin($request)) {
            return $this->alert('danger', 'novaVersandkosten.tourNotify.errorGeneral');
        }
        // Honeypot ausgefüllt: so tun, als wäre alles gut
        if (trim((string) $request->request->get('novaTourWebsite', '')) !== '') {
            return $this->alert('success', 'novaVersandkosten.tourNotify.success');
        }
        if (!$request->request->getBoolean('novaTourConsent')) {
            return $this->alert('danger', 'novaVersandkosten.tourNotify.errorConsent');
        }
        $email = mb_substr((string) $request->request->get('email', ''), 0, 254);
        $postcode = mb_substr((string) $request->request->get('postcode', ''), 0, 10);

        if (!$this->limiter->consume((string) $request->getClientIp(), $salesChannelId)) {
            return $this->alert('info', 'novaVersandkosten.tourNotify.errorTooMany');
        }

        $result = $this->notifications->subscribe($email, $postcode, $salesChannelId, $context->getLanguageId());

        return match ($result) {
            'email' => $this->alert('danger', 'novaVersandkosten.tourNotify.errorEmail'),
            'postcode' => $this->alert('danger', 'novaVersandkosten.tourNotify.errorPostcode'),
            default => $this->alert('success', 'novaVersandkosten.tourNotify.success'),
        };
    }

    /*
     * Links aus den Mails: GET zeigt nur eine Zwischenseite mit Knopf, erst POST ändert etwas (seit 1.4.0).
     * Mail-Sicherheitsscanner und Link-Vorschauen rufen Links automatisch ab – sie lösen so weder eine
     * Bestätigung (Double-Opt-in-Nachweis) noch eine Abmeldung aus.
     */

    #[Route(path: '/nova-versandkosten/tour-benachrichtigung/bestaetigen/{token}', name: 'frontend.nova_versandkosten.tour_notify.confirm', requirements: ['token' => '[A-Za-z0-9_\-]{40,64}'], defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['GET'])]
    public function confirmPage(string $token, Request $request, SalesChannelContext $context): Response
    {
        return $this->actionPage($request, $context, 'confirm', $this->generateUrl('frontend.nova_versandkosten.tour_notify.confirm.save', ['token' => $token]));
    }

    #[Route(path: '/nova-versandkosten/tour-benachrichtigung/bestaetigen/{token}', name: 'frontend.nova_versandkosten.tour_notify.confirm.save', requirements: ['token' => '[A-Za-z0-9_\-]{40,64}'], defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['POST'])]
    public function confirm(string $token): Response
    {
        $ok = $this->notifications->confirm($token);
        $this->addFlash($ok ? self::SUCCESS : self::DANGER, $this->trans($ok ? 'novaVersandkosten.tourNotify.confirmed' : 'novaVersandkosten.tourNotify.confirmFailed'));

        return $this->redirectToRoute('frontend.home.page');
    }

    #[Route(path: '/nova-versandkosten/tour-benachrichtigung/abmelden/{id}/{signature}', name: 'frontend.nova_versandkosten.tour_notify.unsubscribe', requirements: ['id' => '[0-9a-f]{32}', 'signature' => '[0-9a-f]{32}'], defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['GET'])]
    public function unsubscribePage(string $id, string $signature, Request $request, SalesChannelContext $context): Response
    {
        return $this->actionPage($request, $context, 'unsubscribe', $this->generateUrl('frontend.nova_versandkosten.tour_notify.unsubscribe.save', ['id' => $id, 'signature' => $signature]));
    }

    #[Route(path: '/nova-versandkosten/tour-benachrichtigung/abmelden/{id}/{signature}', name: 'frontend.nova_versandkosten.tour_notify.unsubscribe.save', requirements: ['id' => '[0-9a-f]{32}', 'signature' => '[0-9a-f]{32}'], defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['POST'])]
    public function unsubscribe(string $id, string $signature): Response
    {
        $ok = $this->notifications->unsubscribe($id, $signature);
        $this->addFlash($ok ? self::SUCCESS : self::DANGER, $this->trans($ok ? 'novaVersandkosten.tourNotify.unsubscribed' : 'novaVersandkosten.tourNotify.linkInvalid'));

        return $this->redirectToRoute('frontend.home.page');
    }

    #[Route(path: '/nova-versandkosten/tour-benachrichtigung/behalten/{id}/{signature}', name: 'frontend.nova_versandkosten.tour_notify.renew', requirements: ['id' => '[0-9a-f]{32}', 'signature' => '[0-9a-f]{32}'], defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['GET'])]
    public function renewPage(string $id, string $signature, Request $request, SalesChannelContext $context): Response
    {
        return $this->actionPage($request, $context, 'renew', $this->generateUrl('frontend.nova_versandkosten.tour_notify.renew.save', ['id' => $id, 'signature' => $signature]));
    }

    #[Route(path: '/nova-versandkosten/tour-benachrichtigung/behalten/{id}/{signature}', name: 'frontend.nova_versandkosten.tour_notify.renew.save', requirements: ['id' => '[0-9a-f]{32}', 'signature' => '[0-9a-f]{32}'], defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['POST'])]
    public function renew(string $id, string $signature): Response
    {
        $ok = $this->notifications->renew($id, $signature);
        $this->addFlash($ok ? self::SUCCESS : self::DANGER, $this->trans($ok ? 'novaVersandkosten.tourNotify.renewed' : 'novaVersandkosten.tourNotify.linkInvalid'));

        return $this->redirectToRoute('frontend.home.page');
    }

    private function actionPage(Request $request, SalesChannelContext $context, string $action, string $url): Response
    {
        return $this->renderStorefront('@NovaVersandkosten/storefront/nova-versandkosten/confirm-action.html.twig', [
            'page' => $this->pageLoader->load($request, $context),
            'novaAction' => [
                'title' => 'novaVersandkosten.tourNotify.' . $action . 'Title',
                'text' => 'novaVersandkosten.tourNotify.' . $action . 'Text',
                'button' => 'novaVersandkosten.tourNotify.' . $action . 'Button',
                'action' => $url,
            ],
        ]);
    }

    #[Route(path: '/account/tour-benachrichtigung', name: 'frontend.nova_versandkosten.tour_notify.account', defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: ['GET'])]
    public function account(Request $request, SalesChannelContext $context, CustomerEntity $customer): Response
    {
        if (!$this->notifications->enabled($context->getSalesChannelId()) || $customer->getGuest()) {
            return $this->redirectToRoute('frontend.account.home.page');
        }
        $page = $this->pageLoader->load($request, $context);

        return $this->renderStorefront('@NovaVersandkosten/storefront/page/account/tour-notification.html.twig', [
            'page' => $page,
            'novaTourAddresses' => $this->notifications->accountAddresses($customer->getId(), $context->getSalesChannelId()),
        ]);
    }

    #[Route(path: '/account/tour-benachrichtigung', name: 'frontend.nova_versandkosten.tour_notify.account.save', defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true], methods: ['POST'])]
    public function accountSave(Request $request, SalesChannelContext $context, CustomerEntity $customer): Response
    {
        if ($this->notifications->enabled($context->getSalesChannelId()) && !$customer->getGuest()) {
            $ok = $this->notifications->setAccountSubscription(
                $customer->getId(),
                (string) $request->request->get('addressId', ''),
                $request->request->getBoolean('subscribe'),
                $context->getSalesChannelId(),
                $context->getLanguageId()
            );
            $this->addFlash($ok ? self::SUCCESS : self::DANGER, $this->trans($ok ? 'novaVersandkosten.tourNotify.accountSaved' : 'novaVersandkosten.tourNotify.accountFailed'));
        }

        return $this->redirectToRoute('frontend.nova_versandkosten.tour_notify.account');
    }

    /**
     * Herkunft prüfen: Origin (bzw. ersatzweise Referer) muss auf diesen Shop zeigen.
     */
    private static function isSameOrigin(Request $request): bool
    {
        $source = (string) ($request->headers->get('Origin') ?: $request->headers->get('Referer'));
        if ($source === '') {
            return false;
        }
        $host = parse_url($source, \PHP_URL_HOST);

        return \is_string($host) && strcasecmp($host, $request->getHost()) === 0;
    }

    private function alert(string $type, string $snippet): JsonResponse
    {
        $html = $this->renderView('@Storefront/storefront/utilities/alert.html.twig', [
            'type' => $type,
            'content' => $this->trans($snippet),
        ]);

        return new JsonResponse([['type' => $type, 'alert' => $html]]);
    }
}
