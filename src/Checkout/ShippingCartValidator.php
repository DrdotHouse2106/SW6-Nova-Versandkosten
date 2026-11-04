<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Calculator\RequestOrder;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Settings;
use Nova\Versandkosten\Service\Tour\TourService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\Checkout\Shipping\Cart\Error\ShippingMethodBlockedError;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Ist eine Plugin-Versandart gewählt, die für den Warenkorb nicht passt, wird sie wie eine gesperrte
 * Versandart behandelt: Shopware wechselt in der Kasse automatisch auf eine passende und zeigt einen
 * Hinweis; bestellen lässt sich mit der unpassenden Versandart nicht.
 *
 * Beipacken: Ist noch keine Bestellung gewählt, blockiert ein eigener Fehler nur das Bestellen (die
 * Versandart bleibt, damit der Kunde wählen kann). Wurde die alte Bestellung inzwischen versendet oder
 * storniert, ist die Versandart gesperrt – auch beim Abschluss (Shopware rechnet dort neu).
 *
 * Tour: Passt kein Termin (mehr), ist die Versandart gesperrt. Fehlt die Termin-Auswahl oder ist der
 * gewählte Termin inzwischen nicht mehr buchbar (geschlossen, Bestellschluss vorbei, Variante festgelegt,
 * an der die Adresse nicht liegt), blockiert TourSelectionError das Bestellen.
 *
 * Ohne gültige Tarife (noTariffs) sind alle Plugin-Versandarten außer der Tour gesperrt („fail closed“).
 *
 * Versandkosten auf Anfrage: Die Zahlart wird auch hier geprüft (nicht nur in der Zahlartenliste der
 * Kasse), damit niemand per Store-API mit einer sofort abbuchenden Zahlart bestellt.
 *
 * Gründe in den Fehlern sind bewusst allgemein – Fehler liefert die Store-API an den Kunden aus.
 */
class ShippingCartValidator implements CartValidatorInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        // Nachberechnung einer bestehenden Bestellung (Administration): nichts sperren
        if ($cart->hasExtension(OrderConverter::ORIGINAL_ID)) {
            return;
        }

        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        $options = $data instanceof ArrayStruct ? $data->get('options') : null;
        $beipack = $data instanceof ArrayStruct ? $data->get('beipack') : null;
        $noTariffs = $data instanceof ArrayStruct && $data->get('noTariffs') === true;

        foreach ($cart->getDeliveries() as $delivery) {
            $method = $delivery->getShippingMethod();
            $key = MethodMap::optionFor($method->getTechnicalName());
            if ($key === null) {
                continue;
            }
            if ($key === OptionResult::TOUR) {
                $this->validateTour($cart, $errors, $method);
                continue;
            }
            if (!\is_array($options)) {
                // Keine gültigen Tarife: alles sperren (fail closed). Nichts zu verschicken (z. B. nur
                // Gutscheine): nur die Sonder-Versandarten sperren, sonst Shopware-Verhalten.
                if ($noTariffs || \in_array($key, [OptionResult::BEIPACK, OptionResult::REQUEST], true)) {
                    $errors->add(new ShippingMethodBlockedError($this->name($method), $method->getId(), 'nicht verfügbar'));
                }
                continue;
            }
            if ($key === OptionResult::BEIPACK && \is_array($beipack) && ($beipack['state'] ?? '') === 'select') {
                $errors->add(new BeipackSelectionError());
                continue;
            }
            $option = $options[$key] ?? null;
            if (\is_array($option) && !empty($option['available'])) {
                if ($key === OptionResult::REQUEST) {
                    $this->validateRequestPayment($errors, $context);
                }
                continue;
            }
            $errors->add(new ShippingMethodBlockedError(
                $this->name($method),
                $method->getId(),
                \is_array($option) && ($option['reasonCode'] ?? '') === OptionResult::REASON_ON_REQUEST ? 'nur auf Anfrage' : 'nicht verfügbar'
            ));
        }
    }

    /**
     * „Versandkosten auf Anfrage“: nur Zahlarten, die beim Bestellen kein Geld einziehen (wie in der Kasse).
     */
    private function validateRequestPayment(ErrorCollection $errors, SalesChannelContext $context): void
    {
        $payment = $context->getPaymentMethod();
        $allowed = RequestOrder::allowedPaymentMethods(
            [$payment->getId() => $payment->getHandlerIdentifier()],
            $this->settings->ids('requestPaymentMethodIds', $context->getSalesChannelId())
        );
        if ($allowed !== []) {
            return;
        }
        $errors->add(new PaymentMethodBlockedError(
            (string) ($payment->getTranslation('name') ?? $payment->getName() ?? ''),
            'Bei „Versandkosten auf Anfrage“ nicht möglich',
            $payment->getId(),
        ));
    }

    private function validateTour(Cart $cart, ErrorCollection $errors, \Shopware\Core\Checkout\Shipping\ShippingMethodEntity $method): void
    {
        $tour = $cart->getExtension(TourService::EXTENSION);
        $data = $tour instanceof ArrayStruct ? $tour->all() : [];
        if (empty($data['available'])) {
            $errors->add(new ShippingMethodBlockedError($this->name($method), $method->getId(), 'kein passender Tourtermin'));

            return;
        }
        $state = (string) ($data['state'] ?? '');
        if ($state !== 'ok') {
            $errors->add(new TourSelectionError($state === 'invalid'));
        }
    }

    private function name(\Shopware\Core\Checkout\Shipping\ShippingMethodEntity $method): string
    {
        return (string) ($method->getTranslation('name') ?? $method->getName() ?? '');
    }
}
