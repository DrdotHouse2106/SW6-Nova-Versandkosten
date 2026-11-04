<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Bestandsbestellungen bis 1.3.1: Einkauf aus order.customFields herauslösen (reine Logik, testbar).
 *
 * Entfernt werden „nova_versandkosten_purchase_net“, „purchaseNet“ je Paket in
 * „nova_versandkosten_packages“ und die Cargoboard-Angebots-ID in „nova_versandkosten_details“.
 * Maße, Gewichte, Einordnung, Verkauf und „Maße fehlen“ bleiben in den Zusatzfeldern
 * (Warenwirtschaft, Lieferschein).
 */
final class OrderCostSplit
{
    public const FIELD_PURCHASE = 'nova_versandkosten_purchase_net';
    public const FIELD_PACKAGES = 'nova_versandkosten_packages';
    public const FIELD_DETAILS = 'nova_versandkosten_details';
    public const FIELD_OPTION = 'nova_versandkosten_option';
    public const FIELD_PRODUCT = 'nova_versandkosten_product';

    /**
     * @param array<string, mixed> $customFields
     *
     * @return array{0: array<string, mixed>, 1: array{purchaseNet: float, data: array<string, mixed>}|null, 2: bool}
     *               bereinigte Zusatzfelder, Einkauf (null = keiner vorhanden), geändert?
     */
    public static function split(array $customFields): array
    {
        $changed = false;
        $hasCost = \array_key_exists(self::FIELD_PURCHASE, $customFields);
        $purchase = is_numeric($customFields[self::FIELD_PURCHASE] ?? null) ? (float) $customFields[self::FIELD_PURCHASE] : 0.0;
        if ($hasCost) {
            unset($customFields[self::FIELD_PURCHASE]);
            $changed = true;
        }

        $packageCosts = [];
        if (\is_array($customFields[self::FIELD_PACKAGES] ?? null)) {
            $packages = [];
            foreach ($customFields[self::FIELD_PACKAGES] as $index => $package) {
                if (\is_array($package) && \array_key_exists('purchaseNet', $package)) {
                    $packageCosts[] = ['purchaseNet' => is_numeric($package['purchaseNet']) ? (float) $package['purchaseNet'] : null, 'sale' => $package['sale'] ?? null, 'tier' => $package['tier'] ?? null];
                    unset($package['purchaseNet']);
                    $changed = true;
                    $hasCost = true;
                } elseif (\is_array($package)) {
                    $packageCosts[] = ['purchaseNet' => null, 'sale' => $package['sale'] ?? null, 'tier' => $package['tier'] ?? null];
                }
                $packages[$index] = $package;
            }
            $customFields[self::FIELD_PACKAGES] = array_is_list($customFields[self::FIELD_PACKAGES]) ? array_values($packages) : $packages;
        }

        $quote = null;
        if (\is_array($customFields[self::FIELD_DETAILS] ?? null) && \array_key_exists('freightQuotationId', $customFields[self::FIELD_DETAILS])) {
            $quote = $customFields[self::FIELD_DETAILS]['freightQuotationId'];
            unset($customFields[self::FIELD_DETAILS]['freightQuotationId']);
            $changed = true;
            $hasCost = $hasCost || ($quote !== null && $quote !== '');
        }

        if (!$hasCost) {
            return [$customFields, null, $changed];
        }

        return [$customFields, [
            'purchaseNet' => $purchase,
            'data' => [
                'option' => \is_string($customFields[self::FIELD_OPTION] ?? null) ? $customFields[self::FIELD_OPTION] : null,
                'productName' => \is_string($customFields[self::FIELD_PRODUCT] ?? null) ? $customFields[self::FIELD_PRODUCT] : '',
                'packages' => $packageCosts,
                'freightQuotationId' => \is_string($quote) && $quote !== '' ? $quote : null,
                'migrated' => true,
            ],
        ], $changed];
    }
}
