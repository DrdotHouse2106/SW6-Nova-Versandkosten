<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\Money;
use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Service\BeipackService;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\QuoteService;
use Nova\Versandkosten\Service\Settings;
use Nova\Versandkosten\Service\Tour\TourService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
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
 * Am Warenkorb hängt als Erweiterung „novaVersandkosten“ nur, was der Kunde sehen darf (Verkaufspreis,
 * verfügbar, Hinweise, Zustellung – die Store-API liefert Warenkorb-Erweiterungen aus). Einkauf,
 * Tarifprodukt, Paketpreise und interne Gründe liegen nur im InternalQuoteStore (Speicher der Anfrage).
 *
 * Ohne gültige Tarifdatei (nie importiert oder gespeicherte Datei ungültig) gilt „fail closed“: die
 * Erweiterung trägt noTariffs = true, der Validator sperrt alle Plugin-Versandarten außer der Tour.
 *
 * „Zu meiner Bestellung beipacken“: Ist diese Versandart gewählt, wird die gewählte alte Bestellung bei
 * jeder Berechnung frisch geprüft und der Differenzpreis gesetzt (Ergebnis unter options.beipack und
 * beipack = {state: ok|select|invalid, reason, orderNumber}).
 *
 * „Lieferung mit unserer Tour“: unabhängig von den Tarifen; passende Termine und gewählter Termin hängen als
 * Erweiterung TourService::EXTENSION am Warenkorb, der Preis kommt aus dem gewählten Termin (ohne Auswahl
 * der günstigste passende – bestellen lässt sich erst nach der Auswahl).
 */
class ShippingCostProcessor implements CartProcessorInterface
{
    public const EXTENSION = 'novaVersandkosten';

    public function __construct(
        private readonly QuoteService $quotes,
        private readonly QuantityPriceCalculator $priceCalculator,
        private readonly Settings $settings,
        private readonly BeipackService $beipack,
        private readonly TourService $tour,
        private readonly InternalQuoteStore $internal,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $toCalculate->removeExtension(self::EXTENSION);
        $toCalculate->removeExtension(TourService::EXTENSION);
        $this->internal->remove($toCalculate->getToken());

        $this->processTour($original, $toCalculate, $context, $behavior);

        $result = $this->quotes->calculate($toCalculate->getLineItems(), $context);
        if ($result === null) {
            if (!$this->quotes->isReady() && self::hasDeliverableItems($toCalculate)) {
                // Keine (gültigen) Tarife: Plugin-Versandarten sperren statt zum Platzhalterpreis 0,00 € verkaufen
                $toCalculate->addExtension(self::EXTENSION, new ArrayStruct(['noTariffs' => true]));
            }

            return;
        }
        $data = $result->toPublicArray();
        $internal = $result->toArray();

        // Bestellung in der Administration bearbeiten: manuell gesetzte Versandkosten nicht überschreiben
        if ($behavior->hasPermission(CheckoutPermissions::SKIP_DELIVERY_PRICE_RECALCULATION) || $original->hasExtension(OrderConverter::ORIGINAL_ID)) {
            $toCalculate->addExtension(self::EXTENSION, new ArrayStruct($data));
            $this->internal->set($toCalculate->getToken(), $internal);

            return;
        }

        $beipack = null;
        foreach ($toCalculate->getDeliveries() as $delivery) {
            if (MethodMap::optionFor($delivery->getShippingMethod()->getTechnicalName()) === OptionResult::BEIPACK) {
                [$beipack, $info] = $this->beipackQuote($original, $toCalculate, $context);
                $data['beipack'] = $internal['beipack'] = $info;
                $data['options'][OptionResult::BEIPACK] = $beipack->toPublicArray();
                $internal['options'][OptionResult::BEIPACK] = $beipack->toArray();
                break;
            }
        }
        $toCalculate->addExtension(self::EXTENSION, new ArrayStruct($data));
        $this->internal->set($toCalculate->getToken(), $internal);

        foreach ($toCalculate->getDeliveries() as $delivery) {
            $option = MethodMap::optionFor($delivery->getShippingMethod()->getTechnicalName());
            if ($option === null) {
                continue;
            }
            $optionResult = $option === OptionResult::BEIPACK ? $beipack : $result->option($option);
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

    private function processTour(Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $deliveries = [];
        foreach ($toCalculate->getDeliveries() as $delivery) {
            if (MethodMap::optionFor($delivery->getShippingMethod()->getTechnicalName()) === OptionResult::TOUR) {
                $deliveries[] = $delivery;
            }
        }
        if ($deliveries === []) {
            return;
        }

        $tour = $this->tour->evaluate($toCalculate->getLineItems(), $context, $this->tour->selection($original));
        $toCalculate->addExtension(TourService::EXTENSION, new ArrayStruct(TourService::publicView($tour)));
        $this->internal->setTour($toCalculate->getToken(), $tour);

        if ($behavior->hasPermission(CheckoutPermissions::SKIP_DELIVERY_PRICE_RECALCULATION) || $original->hasExtension(OrderConverter::ORIGINAL_ID) || empty($tour['available'])) {
            return;
        }
        $prices = array_map('intval', array_column((array) $tour['options'], 'priceCents'));
        if ($prices === []) {
            return;
        }
        $cents = \is_array($tour['selected'] ?? null) ? (int) $tour['selected']['priceCents'] : min($prices);
        foreach ($deliveries as $delivery) {
            if ($this->onlyShippingFree($delivery->getPositions()->getLineItems()->getFlat())) {
                continue;
            }
            $price = $this->priceFor($cents, true, $context);
            $taxRules = $delivery->getShippingCosts()->getTaxRules();
            $delivery->setShippingCosts($this->priceCalculator->calculate(new QuantityPriceDefinition($price, $taxRules, 1), $context));
        }
    }

    /**
     * @return array{0: OptionResult, 1: array{state: string, reason: string, orderId: string|null, orderNumber: string|null}}
     */
    private function beipackQuote(Cart $original, Cart $toCalculate, SalesChannelContext $context): array
    {
        $selection = $this->beipack->selection($original);
        if ($selection === null) {
            // Auswahl fehlt noch: Versandart bleibt wählbar, der Validator verlangt die Auswahl vor dem Bestellen
            $option = OptionResult::unavailable(OptionResult::BEIPACK, 'Bitte Bestellung wählen');
            $option->available = true;

            return [$option, ['state' => 'select', 'reason' => '', 'orderId' => null, 'orderNumber' => null]];
        }

        [$row, $reason] = $this->beipack->validate($selection, $context);
        if ($row === null || $reason !== null) {
            return [
                OptionResult::unavailable(OptionResult::BEIPACK, (string) $reason),
                ['state' => 'invalid', 'reason' => (string) $reason, 'orderId' => $selection['orderId'], 'orderNumber' => $row === null ? null : (string) $row['order_number']],
            ];
        }

        $quote = $this->beipack->quote($row, $toCalculate->getLineItems(), $context);

        return [$quote, [
            'state' => $quote->available ? 'ok' : 'invalid',
            'reason' => $quote->reason,
            'orderId' => (string) $row['id'],
            'orderNumber' => (string) $row['order_number'],
        ]];
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

    /** Enthält der Warenkorb etwas, das verschickt wird (Position mit Lieferinformation)? */
    public static function hasDeliverableItems(Cart $cart): bool
    {
        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            if ($lineItem->getDeliveryInformation() !== null && $lineItem->isShippingCostAware()) {
                return true;
            }
        }

        return false;
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
