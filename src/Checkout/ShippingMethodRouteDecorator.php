<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Service\BeipackService;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Settings;
use Nova\Versandkosten\Service\Tour\TourService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\SalesChannel\ShippingMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Blendet Plugin-Versandarten aus, die für den aktuellen Warenkorb nicht passen (Gewicht, Maße,
 * Warenwert, Land, Speditionsware …), und hängt an die übrigen Preis, Zustelldatum und Hinweise
 * (Erweiterung „novaVersandkosten“ an der Versandart, für Storefront und Store-API).
 *
 * Fremde Versandarten (z. B. „Abholung auf Treffen“ aus Nova Termine) bleiben unberührt.
 *
 * „Zu meiner Bestellung beipacken“ erscheint nur, wenn es etwas zum Beipacken gibt: eine gewählte,
 * noch gültige Bestellung, eigene offene Bestellungen (Kundenkonto) oder – für Gäste, wenn eingestellt –
 * das Formular „Bestellnummer + E-Mail + PLZ“.
 *
 * „Lieferung mit unserer Tour“ erscheint nur, wenn mindestens ein offener/festgelegter Termin zu Warenkorb
 * und Lieferadresse passt (Preis des gewählten Termins, sonst „ab“ dem günstigsten) – auch ohne Tarife.
 */
class ShippingMethodRouteDecorator extends AbstractShippingMethodRoute
{
    public function __construct(
        private readonly AbstractShippingMethodRoute $decorated,
        private readonly CartService $cartService,
        private readonly Settings $settings,
        private readonly BeipackService $beipack,
        private readonly TourService $tour,
    ) {
    }

    public function getDecorated(): AbstractShippingMethodRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ShippingMethodRouteResponse
    {
        $response = $this->decorated->load($request, $context, $criteria);
        $methods = $response->getShippingMethods();

        $ours = false;
        foreach ($methods as $method) {
            if (MethodMap::optionFor($method->getTechnicalName()) !== null) {
                $ours = true;
                break;
            }
        }
        if (!$ours) {
            return $response;
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        foreach ($methods->getElements() as $id => $method) {
            if (MethodMap::optionFor($method->getTechnicalName()) !== OptionResult::TOUR) {
                continue;
            }
            $state = $this->tourState($cart, $context);
            if ($state === null) {
                self::removeMethod($response, (string) $id);
                continue;
            }
            $method->addExtension(ShippingCostProcessor::EXTENSION, new ArrayStruct($state));
        }

        $data = $cart->getExtension(ShippingCostProcessor::EXTENSION);
        $options = $data instanceof ArrayStruct ? $data->get('options') : null;
        if (!\is_array($options)) {
            // Keine gültigen Tarife (noTariffs): alle Plugin-Versandarten außer der Tour ausblenden (fail closed).
            // Nichts zu verschicken (z. B. nur Gutscheine): nur die Sonder-Versandarten ausblenden.
            $noTariffs = $data instanceof ArrayStruct && $data->get('noTariffs') === true;
            foreach ($methods->getElements() as $id => $method) {
                $key = MethodMap::optionFor($method->getTechnicalName());
                if ($key === null || $key === OptionResult::TOUR) {
                    continue;
                }
                if ($noTariffs || \in_array($key, [OptionResult::BEIPACK, OptionResult::REQUEST], true)) {
                    self::removeMethod($response, (string) $id);
                }
            }

            return $response;
        }

        $showEstimate = $this->settings->bool('showEstimate', $context->getSalesChannelId(), true);
        foreach ($methods->getElements() as $id => $method) {
            $key = MethodMap::optionFor($method->getTechnicalName());
            if ($key === null || $key === OptionResult::TOUR) {
                continue;
            }
            if ($key === OptionResult::BEIPACK) {
                $state = $this->beipackState($options[$key] ?? null, $data instanceof ArrayStruct ? $data->get('beipack') : null, $cart, $context);
                if ($state === null) {
                    self::removeMethod($response, (string) $id);
                    continue;
                }
                $method->addExtension(ShippingCostProcessor::EXTENSION, new ArrayStruct($state));
                continue;
            }
            $option = $options[$key] ?? null;
            if (!\is_array($option) || empty($option['available'])) {
                self::removeMethod($response, (string) $id);
                continue;
            }
            $method->addExtension(ShippingCostProcessor::EXTENSION, new ArrayStruct(OptionPresenter::present($option, $context, $showEstimate)));
        }

        return $response;
    }

    /**
     * Anzeige-Daten für „Lieferung mit unserer Tour“ oder null (ausblenden). Keine Strecke, keine Orte.
     *
     * @return array<string, mixed>|null
     */
    private function tourState(\Shopware\Core\Checkout\Cart\Cart $cart, SalesChannelContext $context): ?array
    {
        $data = $cart->getExtension(TourService::EXTENSION);
        $tour = $data instanceof ArrayStruct ? $data->all() : $this->tour->evaluate($cart->getLineItems(), $context, $this->tour->selection($cart));
        if (empty($tour['available']) || !\is_array($tour['options'] ?? null) || $tour['options'] === []) {
            return null;
        }
        $selected = \is_array($tour['selected'] ?? null) ? $tour['selected'] : null;
        $prices = array_column($tour['options'], 'price');

        return [
            'key' => OptionResult::TOUR,
            'tour' => true,
            'available' => true,
            'price' => $selected !== null ? (float) $selected['price'] : (float) min($prices),
            'priceFrom' => $selected === null && \count(array_unique($prices)) > 1,
            'dateLabel' => $selected !== null ? (string) $selected['label'] : null,
            'nextDate' => (string) $tour['options'][0]['label'],
            'dateCount' => \count($tour['options']),
            'vatIncluded' => true,
            'hints' => [],
            'packageCount' => 0,
            'recommended' => false,
            'delivery' => null,
            'onRequest' => false,
            'beipack' => false,
        ];
    }

    /**
     * Anzeige-Daten für „beipacken“ oder null (ausblenden).
     *
     * @return array<string, mixed>|null
     */
    private function beipackState(mixed $option, mixed $info, \Shopware\Core\Checkout\Cart\Cart $cart, SalesChannelContext $context): ?array
    {
        // Versandart ist gewählt: Ergebnis der Berechnung
        if (\is_array($option) && \is_array($info)) {
            $state = (string) ($info['state'] ?? '');
            if ($state === 'invalid' || empty($option['available'])) {
                return null;
            }
            $presented = OptionPresenter::present($option, $context, false);
            $presented['beipack'] = true;
            $presented['orderNumber'] = $info['orderNumber'] ?? null;
            if ($state === 'select') {
                $presented['price'] = null;
            }

            return $presented;
        }

        // Nicht gewählt: anbieten, wenn eine gültige Auswahl oder passende Bestellungen vorhanden sind
        $base = ['key' => OptionResult::BEIPACK, 'beipack' => true, 'available' => true, 'price' => null, 'hints' => [], 'packageCount' => 0, 'vatIncluded' => true, 'recommended' => false, 'delivery' => null];
        $selection = $this->beipack->selection($cart);
        if ($selection !== null) {
            [$row, $reason] = $this->beipack->validate($selection, $context);
            if ($row !== null && $reason === null) {
                return $base + ['orderNumber' => (string) $row['order_number']];
            }
        }
        $customer = $context->getCustomer();
        if ($customer !== null && !$customer->getGuest()) {
            return $this->beipack->candidates($context) !== [] ? $base + ['orderNumber' => null] : null;
        }

        return $this->settings->bool('beipackGuestForm', $context->getSalesChannelId(), true) ? $base + ['orderNumber' => null] : null;
    }

    /**
     * Entfernt eine Versandart aus der Antwort. Das Suchergebnis führt die Elemente doppelt (als eigene
     * Collection und in getEntities()); beide müssen gleich sein, sonst bricht die Store-API beim Kodieren ab
     * („Invalid struct: Entity at index …“).
     */
    private static function removeMethod(ShippingMethodRouteResponse $response, string $id): void
    {
        $response->getShippingMethods()->remove($id);
        $response->getObject()->remove($id);
    }
}
