<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\Money;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\QuoteService;
use Nova\Versandkosten\Service\Settings;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\CheckoutPermissions;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Setzt den Versandpreis der Plugin-Versandarten. Läuft direkt nach Shopwares DeliveryProcessor
 * (Priorität -5000) und vor Versand-Rabatten aus Promotions (-5100), damit Gutscheine
 * „versandkostenfrei“ weiter greifen.
 *
 * Shopware hat die Lieferung schon mit dem Platzhalterpreis (0 €) und den passenden Steuerregeln
 * berechnet; diese Steuerregeln übernehmen wir und ersetzen nur den Betrag.
 * Das Ergebnis aller Optionen hängt als Erweiterung „novaVersandkosten“ am Warenkorb (Anzeige, Bestellung).
 */
class ShippingCostProcessor implements CartProcessorInterface
{
    public const EXTENSION = 'novaVersandkosten';

    public function __construct(
        private readonly QuoteService $quotes,
        private readonly QuantityPriceCalculator $priceCalculator,
        private readonly Settings $settings,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $toCalculate->removeExtension(self::EXTENSION);

        $result = $this->quotes->calculate($toCalculate->getLineItems(), $context);
        if ($result === null) {
            return;
        }
        $toCalculate->addExtension(self::EXTENSION, new ArrayStruct($result->toArray()));

        // Bestellung in der Administration bearbeiten: manuell gesetzte Versandkosten nicht überschreiben
        if ($behavior->hasPermission(CheckoutPermissions::SKIP_DELIVERY_PRICE_RECALCULATION)) {
            return;
        }

        foreach ($toCalculate->getDeliveries() as $delivery) {
            $option = MethodMap::optionFor($delivery->getShippingMethod()->getTechnicalName());
            if ($option === null) {
                continue;
            }
            $optionResult = $result->option($option);
            if ($optionResult === null || !$optionResult->available) {
                // Validator sperrt die Versandart, Shopware wechselt auf eine passende
                continue;
            }
            // Nur „versandkostenfreie“ Artikel (Shopware-Produktschalter): Shopware hat schon 0 € gesetzt
            if ($this->onlyShippingFree($delivery->getPositions()->getLineItems()->getFlat())) {
                continue;
            }

            $price = $this->priceFor($optionResult->saleCents, $optionResult->vatIncluded, $context);
            $taxRules = $delivery->getShippingCosts()->getTaxRules();
            $delivery->setShippingCosts($this->priceCalculator->calculate(new QuantityPriceDefinition($price, $taxRules, 1), $context));
        }
    }

    /**
     * Brutto-Preis aus dem Kern in den Preis umrechnen, den Shopware für den Steuerstatus erwartet.
     */
    private function priceFor(int $saleCents, bool $vatIncluded, SalesChannelContext $context): float
    {
        $price = Money::centsToFloat($saleCents);
        if ($vatIncluded && $context->getTaxState() !== CartPrice::TAX_STATE_GROSS) {
            $vat = $this->settings->rules($context->getSalesChannelId())->vatPercent;
            $price = round($price / (1 + $vat / 100), 2);
        }

        return round($price * $context->getContext()->getCurrencyFactor(), 2);
    }

    /**
     * @param array<\Shopware\Core\Checkout\Cart\LineItem\LineItem> $lineItems
     */
    private function onlyShippingFree(array $lineItems): bool
    {
        $any = false;
        foreach ($lineItems as $lineItem) {
            $info = $lineItem->getDeliveryInformation();
            if ($info === null) {
                continue;
            }
            $any = true;
            if (!$info->getFreeDelivery()) {
                return false;
            }
        }

        return $any;
    }
}
