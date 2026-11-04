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
        if ($this->tariffs->get() === null) {
            return null;
        }

        $salesChannelId = $context->getSalesChannelId();
        $items = $this->classifier->itemsFromCart($lineItems, $salesChannelId, $context->getLanguageId());
        $location = $context->getShippingLocation();

        return $this->calculateItems(
            $items,
            (string) $location->getCountry()->getIso(),
            (string) ($location->getAddress()?->getZipcode() ?? ''),
            (string) ($location->getAddress()?->getCompany() ?? ''),
            $salesChannelId,
        );
    }

    /**
     * @param list<\Nova\Versandkosten\Calculator\Item> $items
     */
    public function calculateItems(array $items, string $country, string $postcode, string $company, string $salesChannelId): ?ShippingResult
    {
        $tariffs = $this->tariffs->get();
        if ($tariffs === null || $items === []) {
            return null;
        }

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
