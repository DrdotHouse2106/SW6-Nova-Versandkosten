<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Nova\Versandkosten\Service\Settings;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Faktor vom Brutto-Preis des Rechenkerns (Versand-Steuersatz, z. B. 19 %) zum Preis, den Shopware im
 * Kontext erwartet: netto bei Netto-Kunden, mit dem Steuersatz des Lieferlandes bei „Versand-MwSt. nach
 * Lieferland (OSS)“, sonst unverändert. Gemeinsam für Warenkorb (ShippingCostProcessor) und Anzeige
 * der Versandarten (OptionPresenter), damit beide denselben Preis zeigen.
 */
final class ShippingVat
{
    public static function factor(Settings $settings, SalesChannelContext $context, bool $vatIncluded): float
    {
        if (!$vatIncluded) {
            return 1.0;
        }

        $vat = $settings->rules($context->getSalesChannelId())->vatPercent;
        if ($context->getTaxState() !== CartPrice::TAX_STATE_GROSS) {
            return 1 / (1 + $vat / 100);
        }

        $countryVat = self::countryVat($settings, $vat, $context);

        return $countryVat === null ? 1.0 : (1 + $countryVat / 100) / (1 + $vat / 100);
    }

    /**
     * Steuersatz des Lieferlandes: Steuerregel der Steuerklasse mit dem Versand-Steuersatz.
     * null = Einstellung aus, keine Regel oder gleicher Satz.
     */
    private static function countryVat(Settings $settings, float $vat, SalesChannelContext $context): ?float
    {
        if (!$settings->bool('ossShippingVat', $context->getSalesChannelId())) {
            return null;
        }
        foreach ($context->getTaxRules() as $tax) {
            if (abs($tax->getTaxRate() - $vat) > 0.0001) {
                continue;
            }
            $rate = $tax->getRules()?->first()?->getTaxRate();

            return $rate !== null && abs($rate - $vat) > 0.0001 ? $rate : null;
        }

        return null;
    }
}
