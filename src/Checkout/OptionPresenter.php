<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Calculator\DeliveryEstimator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Bereitet eine Option (Array aus OptionResult::toArray) für die Anzeige in der Kasse auf.
 */
final class OptionPresenter
{
    /**
     * @param array<string, mixed> $option
     *
     * @return array<string, mixed>
     */
    public static function present(array $option, SalesChannelContext $context, bool $showEstimate): array
    {
        $locale = $context->getLanguageInfo()->localeCode;
        $delivery = null;
        if ($showEstimate && \is_string($option['deliveryFrom'] ?? null) && \is_string($option['deliveryTo'] ?? null)) {
            $delivery = DeliveryEstimator::formatRange(
                new \DateTimeImmutable($option['deliveryFrom']),
                new \DateTimeImmutable($option['deliveryTo']),
                $locale
            );
        }

        $hints = [];
        foreach ((array) ($option['hints'] ?? []) as $hint) {
            if (\is_array($hint) && isset($hint['code'])) {
                $hints[] = ['code' => (string) $hint['code'], 'carrier' => (string) ($hint['carrier'] ?? '')];
            }
        }

        return [
            'key' => (string) ($option['key'] ?? ''),
            'available' => (bool) ($option['available'] ?? false),
            'price' => round((float) ($option['sale'] ?? 0) * $context->getContext()->getCurrencyFactor(), 2),
            'vatIncluded' => (bool) ($option['vatIncluded'] ?? true),
            'packageCount' => (int) ($option['packageCount'] ?? 0),
            'delivery' => $delivery,
            'hints' => $hints,
            'recommended' => (bool) ($option['recommended'] ?? false),
            'carrierName' => (string) ($option['carrierName'] ?? ''),
            'reasonCode' => (string) ($option['reasonCode'] ?? ''),
        ];
    }
}
