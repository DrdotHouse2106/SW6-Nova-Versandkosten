<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service\Tour;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\OptionResult;
use Nova\Versandkosten\Calculator\Tour\TourMatch;
use Nova\Versandkosten\Calculator\Tour\TourPlanner;
use Nova\Versandkosten\Calculator\Tour\TourText;
use Nova\Versandkosten\Calculator\Tour\TourTier;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\ProductClassifier;
use Nova\Versandkosten\Service\Settings;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * „Lieferung mit unserer Tour“ in der Kasse: passende Termine für Warenkorb und Lieferadresse, gewählter
 * Termin (Erweiterung SELECTION am Warenkorb, wird mit ihm gespeichert) und Preis.
 *
 * Ergebnis (Erweiterung EXTENSION am berechneten Warenkorb):
 * {available, reason, tier, state: ok|select|invalid, options: [{id, date, label, price, priceCents,
 * variantId, deviationKm}], selectedId, selected, minPrice}. Strecken und Orte stehen bewusst nicht darin.
 */
class TourService implements ResetInterface
{
    public const EXTENSION = 'novaVersandkostenTour';
    public const SELECTION = 'novaVersandkostenTourWahl';

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly TourRepository $repository,
        private readonly PostcodeDirectory $postcodes,
        private readonly ProductClassifier $classifier,
        private readonly Settings $settings,
        private readonly Connection $connection,
    ) {
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    public function selection(Cart $cart): ?string
    {
        $ext = $cart->getExtension(self::SELECTION);
        $id = $ext instanceof ArrayStruct ? $ext->get('dateId') : null;

        return \is_string($id) && Uuid::isValid($id) ? strtolower($id) : null;
    }

    public function select(Cart $cart, ?string $dateId): void
    {
        if ($dateId === null || !Uuid::isValid($dateId)) {
            $cart->removeExtension(self::SELECTION);

            return;
        }
        $cart->addExtension(self::SELECTION, new ArrayStruct(['dateId' => strtolower($dateId)]));
    }

    /**
     * ID der Tour-Versandart, wenn sie aktiv und dem Verkaufskanal zugeordnet ist.
     */
    public function methodId(string $salesChannelId): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(sm.id)) FROM shipping_method sm
              INNER JOIN sales_channel_shipping_method scsm ON scsm.shipping_method_id = sm.id
              WHERE sm.technical_name = :name AND sm.active = 1 AND scsm.sales_channel_id = :sc LIMIT 1',
            ['name' => MethodMap::technicalName(OptionResult::TOUR), 'sc' => Uuid::fromHexToBytes($salesChannelId)]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Passende Termine und gewählter Termin für Warenkorb + Lieferadresse.
     *
     * @return array<string, mixed>
     */
    public function evaluate(LineItemCollection $lineItems, SalesChannelContext $context, ?string $selectedId): array
    {
        $salesChannelId = $context->getSalesChannelId();
        $location = $context->getShippingLocation();
        $country = (string) $location->getCountry()->getIso();
        $postcode = (string) ($location->getAddress()?->getZipcode() ?? '');
        $items = $this->classifier->itemsFromCart($lineItems, $salesChannelId, $context->getLanguageId());

        $key = md5(serialize([$items, $country, $postcode, $salesChannelId, $selectedId, $context->getContext()->getCurrencyFactor()]));
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $pricing = $this->settings->tourPricing($salesChannelId);
        $tier = TourTier::fromItems($items);
        $base = ['available' => false, 'reason' => '', 'tier' => $tier, 'state' => 'invalid', 'options' => [], 'selectedId' => $selectedId, 'selected' => null, 'minPrice' => null];

        if ($tier === null) {
            return $this->memo[$key] = ['reason' => $items === [] ? 'empty' : TourMatch::REASON_TIER] + $base;
        }
        if (!$pricing->isCountryAllowed($country)) {
            return $this->memo[$key] = ['reason' => 'country'] + $base;
        }
        $point = $postcode === '' ? null : $this->postcodes->point($country, $postcode);
        if ($point === null) {
            return $this->memo[$key] = ['reason' => 'postcode'] + $base;
        }

        $locale = $context->getLanguageInfo()->localeCode;
        $factor = $context->getContext()->getCurrencyFactor();
        $options = [];
        $dates = [];
        foreach ($this->repository->bookableDates() as $date) {
            $dates[$date->id] = $date;
        }
        foreach (TourPlanner::available(array_values($dates), $point, $tier, $pricing, TourRepository::today()) as $match) {
            $date = $dates[$match->dateId] ?? null;
            if ($date === null || $match->priceCents === null) {
                continue;
            }
            $options[] = [
                'id' => $match->dateId,
                'date' => $date->date->format('Y-m-d'),
                'label' => TourText::date($date->date, $locale),
                'deadline' => $date->deadline->format('Y-m-d'),
                'priceCents' => $match->priceCents,
                'price' => round($match->priceCents / 100 * $factor, 2),
                'variantId' => $match->variantId,
                'deviationKm' => $match->deviationKm === null ? null : round($match->deviationKm, 1),
            ];
        }
        if ($options === []) {
            return $this->memo[$key] = ['reason' => 'noDate'] + $base;
        }

        $selected = null;
        foreach ($options as $option) {
            if ($option['id'] === $selectedId) {
                $selected = $option;
                break;
            }
        }
        $state = $selected !== null ? 'ok' : ($selectedId === null ? 'select' : 'invalid');

        return $this->memo[$key] = [
            'available' => true,
            'reason' => '',
            'tier' => $tier,
            'state' => $state,
            'options' => $options,
            'selectedId' => $selected['id'] ?? $selectedId,
            'selected' => $selected,
            'minPrice' => min(array_column($options, 'price')),
        ];
    }

    /**
     * Ergebnis ohne interne Angaben (Variante, Abweichung in km) – für die Warenkorb-Erweiterung, die die
     * Store-API ausliefert.
     *
     * @param array<string, mixed> $tour
     *
     * @return array<string, mixed>
     */
    public static function publicView(array $tour): array
    {
        $strip = static fn (mixed $o): mixed => \is_array($o) ? array_diff_key($o, ['variantId' => true, 'deviationKm' => true]) : $o;
        $tour['options'] = array_map($strip, \is_array($tour['options'] ?? null) ? $tour['options'] : []);
        $tour['selected'] = $strip($tour['selected'] ?? null);

        return $tour;
    }

    public function hint(?string $salesChannelId): string
    {
        return $this->settings->string('tourCheckoutHint', $salesChannelId);
    }
}
