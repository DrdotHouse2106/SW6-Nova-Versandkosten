<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Nova\Versandkosten\Calculator\Destination;
use Nova\Versandkosten\Calculator\ShippingCalculator;
use Nova\Versandkosten\Calculator\ShippingResult;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Verbindet Shopware mit dem Rechenkern: Warenkorb + Kontext → ShippingResult (je Anfrage gemerkt).
 */
class QuoteService implements ResetInterface
{
    /** @var array<string, ShippingResult|null> */
    private array $memo = [];

    public function __construct(
        private readonly TariffStore $tariffs,
        private readonly Settings $settings,
        private readonly ProductClassifier $classifier,
        private readonly CargoboardQuoteProvider $cargoboard,
    ) {
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    public function isReady(): bool
    {
        return $this->tariffs->get() !== null;
    }

    public function calculate(LineItemCollection $lineItems, SalesChannelContext $context): ?ShippingResult
    {
        $tariffs = $this->tariffs->get();
        if ($tariffs === null) {
            return null;
        }

        $salesChannelId = $context->getSalesChannelId();
        $items = $this->classifier->itemsFromCart($lineItems, $salesChannelId);
        if ($items === []) {
            return null;
        }

        $location = $context->getShippingLocation();
        $country = (string) $location->getCountry()->getIso();
        $postcode = (string) ($location->getAddress()?->getZipcode() ?? '');
        $company = (string) ($location->getAddress()?->getCompany() ?? '');

        $key = md5(serialize([$items, $country, $postcode, $company, $salesChannelId]));
        if (\array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));

        return $this->memo[$key] = (new ShippingCalculator())->calculate(
            $items,
            new Destination($country, $postcode),
            $this->settings->rules($salesChannelId),
            $tariffs,
            $now,
            $this->cargoboard->forChannel($salesChannelId, $company === ''),
            $this->settings->estimator($salesChannelId),
        );
    }
}
