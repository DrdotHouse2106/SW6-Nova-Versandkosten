<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Service\MethodMap;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Shipping\Cart\Error\ShippingMethodBlockedError;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Ist eine Plugin-Versandart gewählt, die für den Warenkorb nicht passt, wird sie wie eine gesperrte
 * Versandart behandelt: Shopware wechselt in der Kasse automatisch auf eine passende und zeigt einen
 * Hinweis; bestellen lässt sich mit der unpassenden Versandart nicht.
 */
class ShippingCartValidator implements CartValidatorInterface
{
    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        if (!$data instanceof ArrayStruct) {
            return;
        }
        $options = $data->get('options');
        if (!\is_array($options)) {
            return;
        }

        foreach ($cart->getDeliveries() as $delivery) {
            $method = $delivery->getShippingMethod();
            $key = MethodMap::optionFor($method->getTechnicalName());
            if ($key === null) {
                continue;
            }
            $option = $options[$key] ?? null;
            if (\is_array($option) && !empty($option['available'])) {
                continue;
            }
            $errors->add(new ShippingMethodBlockedError(
                (string) ($method->getTranslation('name') ?? $method->getName() ?? ''),
                $method->getId(),
                \is_array($option) ? (string) ($option['reason'] ?? 'nicht verfügbar') : 'nicht verfügbar'
            ));
        }
    }
}
