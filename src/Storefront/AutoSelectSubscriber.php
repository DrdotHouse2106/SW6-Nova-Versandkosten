<?php declare(strict_types=1);

namespace Nova\Versandkosten\Storefront;

use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Checkout\AutoSelectDecision;
use Nova\Versandkosten\Checkout\ShippingCostProcessor;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Settings;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Shipping\Cart\Error\ShippingMethodBlockedError;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Checkout\Cart\Error\ShippingMethodChangedError;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wählt im Warenkorb (Seite, Offcanvas) und in der Kasse automatisch die günstigste mögliche
 * Versandart des Plugins vor – statt fest der Standard-Versandart des Kanals. Eine Versandart, die der
 * Kunde selbst gewählt hat, bleibt, solange sie möglich ist. Ist die gewählte Versandart nicht (mehr)
 * möglich, wird sofort gewechselt, damit der Warenkorb nie „gesperrt“ mit 0,00 € zeigt.
 *
 * Läuft vor dem Seitenaufbau (Controller-Event nach der Kontext-Auflösung), damit Seite, Summen und
 * Auswahl schon die neue Versandart zeigen. Was der Kunde selbst wählt, merkt sich die Sitzung.
 */
class AutoSelectSubscriber implements EventSubscriberInterface
{
    public const SESSION_KEY = 'novaVersandkostenManualShipping';

    private const PAGES = ['frontend.checkout.cart.page', 'frontend.cart.offcanvas', 'frontend.checkout.confirm.page'];
    private const CONFIGURE = 'frontend.checkout.configure';
    private const FINISH = 'frontend.checkout.finish.page';

    public function __construct(
        private readonly Settings $settings,
        private readonly CartService $cartService,
        private readonly AbstractShippingMethodRoute $shippingMethodRoute,
        private readonly AbstractContextSwitchRoute $contextSwitchRoute,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_CONTEXT_RESOLVE - 20],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$context instanceof SalesChannelContext || !$request->hasSession()) {
            return;
        }

        try {
            if ($route === self::CONFIGURE) {
                $this->rememberManual($request, $context);
            } elseif ($route === self::FINISH) {
                $request->getSession()->remove(self::SESSION_KEY);
            } elseif (\in_array($route, self::PAGES, true)
                && $this->settings->bool('autoSelectCheapest', $context->getSalesChannelId(), true)) {
                $this->select($request, $context);
            }
        } catch (\Throwable $e) {
            // Nie die Kasse blockieren: im Zweifel bleibt Shopwares Verhalten
            $this->logger->warning('Nova Versandkosten: Vorauswahl der Versandart fehlgeschlagen', ['exception' => $e]);
        }
    }

    /** Wechselt der Kunde selbst die Versandart, wird sie gemerkt (Länderwechsel schickt die alte mit). */
    private function rememberManual(Request $request, SalesChannelContext $context): void
    {
        $id = $request->request->get(SalesChannelContextService::SHIPPING_METHOD_ID);
        if (!\is_string($id) || $id === '' || $id === $context->getShippingMethod()->getId()) {
            return;
        }
        $request->getSession()->set(self::SESSION_KEY, $id);
    }

    private function select(Request $request, SalesChannelContext $context): void
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ($cart->getLineItems()->count() === 0) {
            return;
        }
        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        $options = $data instanceof ArrayStruct ? $data->get('options') : null;
        if (!\is_array($options)) {
            return;
        }

        $current = $context->getShippingMethod();
        $currentKey = MethodMap::optionFor($current->getTechnicalName());
        $session = $request->getSession();
        $manual = $session->get(self::SESSION_KEY) === $current->getId();
        $blockedError = $this->blockedError($cart, $current->getId());
        $blocked = $blockedError !== null;

        // Beipacken, Tour, Abholung: nur bei Sperre eingreifen
        if (!$blocked && $currentKey !== null && !\in_array($currentKey, OptionResult::SHIPPING, true)) {
            return;
        }

        $methods = $this->shippingMethodRoute
            ->load(new Request(['onlyAvailable' => true]), $context, new Criteria())
            ->getShippingMethods();

        $candidates = [];
        foreach ($methods as $method) {
            $key = MethodMap::optionFor($method->getTechnicalName());
            if ($key === null || (!\in_array($key, OptionResult::SHIPPING, true) && $key !== OptionResult::REQUEST)) {
                continue;
            }
            $option = $options[$key] ?? null;
            if (!\is_array($option) || empty($option['available'])) {
                continue;
            }
            $candidates[$key] = ['id' => $method->getId(), 'price' => (float) ($option['sale'] ?? 0.0), 'entity' => $method];
        }

        $targetId = AutoSelectDecision::choose(
            $currentKey,
            $current->getId(),
            $blocked,
            $manual,
            array_map(static fn (array $c) => ['id' => $c['id'], 'price' => $c['price']], $candidates)
        );
        if ($targetId === null) {
            return;
        }
        $target = $methods->get($targetId);
        if (!$target instanceof ShippingMethodEntity) {
            return;
        }

        $this->switchTo($cart, $context, $current, $target, $blockedError);
        if ($blocked) {
            $session->remove(self::SESSION_KEY);
        }
    }

    private function switchTo(Cart $cart, SalesChannelContext $context, ShippingMethodEntity $old, ShippingMethodEntity $new, ?ShippingMethodBlockedError $blocked): void
    {
        $updated = clone $context;
        $updated->assign(['shippingMethod' => $new]);
        $newCart = $this->cartService->recalculate($cart, $updated);
        if ($this->blockedError($newCart, $new->getId()) !== null) {
            // Neue Wahl passt doch nicht: alles beim Alten lassen
            $this->cartService->recalculate($cart, $context);

            return;
        }

        $this->contextSwitchRoute->switchContext(
            new RequestDataBag([SalesChannelContextService::SHIPPING_METHOD_ID => $new->getId()]),
            $updated
        );
        $context->assign(['shippingMethod' => $new]);
        $context->setRuleIds($updated->getRuleIds());
        $context->setAreaRuleIds($updated->getAreaRuleIds());

        if ($blocked !== null) {
            $newCart->getErrors()->add(new ShippingMethodChangedError(
                oldShippingMethodName: $blocked->getName(),
                newShippingMethodName: (string) ($new->getTranslation('name') ?? $new->getName() ?? ''),
                oldShippingMethodId: $old->getId(),
                newShippingMethodId: $new->getId(),
                reason: (string) ($blocked->getReason() ?? ''),
            ));
        }
        $this->cartService->setCart($newCart);
    }

    private function blockedError(Cart $cart, string $methodId): ?ShippingMethodBlockedError
    {
        foreach ($cart->getErrors() as $error) {
            if ($error instanceof ShippingMethodBlockedError && $error->getShippingMethodId() === $methodId) {
                return $error;
            }
        }

        return null;
    }
}
