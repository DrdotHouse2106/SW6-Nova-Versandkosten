<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Service\MethodMap;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
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
 */
class ShippingCartValidator implements CartValidatorInterface
{
    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        // Nachberechnung einer bestehenden Bestellung (Administration): nichts sperren
        if ($cart->hasExtension(OrderConverter::ORIGINAL_ID)) {
            return;
        }

        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        $options = $data instanceof ArrayStruct ? $data->get('options') : null;
        $beipack = $data instanceof ArrayStruct ? $data->get('beipack') : null;

        foreach ($cart->getDeliveries() as $delivery) {
            $method = $delivery->getShippingMethod();
            $key = MethodMap::optionFor($method->getTechnicalName());
            if ($key === null) {
                continue;
            }
            if (!\is_array($options)) {
                // Ohne Berechnung (keine Tarife): nur die Sonder-Versandarten sperren, sonst Shopware-Verhalten
                if (\in_array($key, [OptionResult::BEIPACK, OptionResult::REQUEST], true)) {
                    $errors->add(new ShippingMethodBlockedError($this->name($method), $method->getId(), 'keine Tarife'));
                }
                continue;
            }
            if ($key === OptionResult::BEIPACK && \is_array($beipack) && ($beipack['state'] ?? '') === 'select') {
                $errors->add(new BeipackSelectionError());
                continue;
            }
            $option = $options[$key] ?? null;
            if (\is_array($option) && !empty($option['available'])) {
                continue;
            }
            $errors->add(new ShippingMethodBlockedError(
                $this->name($method),
                $method->getId(),
                \is_array($option) && (string) ($option['reason'] ?? '') !== '' ? (string) $option['reason'] : 'nicht verfügbar'
            ));
        }
    }

    private function name(\Shopware\Core\Checkout\Shipping\ShippingMethodEntity $method): string
    {
        return (string) ($method->getTranslation('name') ?? $method->getName() ?? '');
    }
}
