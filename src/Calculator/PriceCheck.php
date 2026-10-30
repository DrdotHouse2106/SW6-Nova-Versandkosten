<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

use Nova\Versandkosten\Calculator\Tariff\TariffSet;

/**
 * Preisprüfung: Festpreise eines Kanals gegen den aktuellen Einkauf (inkl. Energiezuschlag und – im
 * Zeitraum – Peak) für typische Pakete im Heimatland. Warnung, wenn der Festpreis netto unter dem
 * Einkauf liegt (Unterdeckung).
 */
final class PriceCheck
{
    /** Bezeichnung → [Option, Gewicht kg, L, B, H] */
    public const SCENARIOS = [
        ['warenpost', 'Warenpost 0,5 kg', 0.5, 25, 18, 3],
        ['kleinpaket', 'Kleinpaket 1 kg', 1.0, 30, 20, 7],
        ['dpd', 'DPD 1 kg', 1.0, 30, 20, 15],
        ['dpd', 'DPD 5 kg', 5.0, 40, 30, 20],
        ['dpd', 'DPD 10 kg', 10.0, 60, 40, 30],
        ['dpd', 'DPD 20 kg', 20.0, 60, 40, 40],
        ['dpd', 'DPD 25 kg', 25.0, 80, 50, 40],
        ['dhl', 'DHL 1 kg', 1.0, 30, 20, 15],
        ['dhl', 'DHL 5 kg', 5.0, 40, 30, 20],
        ['dhl', 'DHL 10 kg', 10.0, 60, 40, 30],
        ['dhl', 'DHL 20 kg', 20.0, 60, 40, 40],
        ['dhl', 'DHL 25 kg', 25.0, 80, 50, 40],
    ];

    /**
     * @return list<array{option: string, label: string, fixed: float|null, saleNet: float|null, purchaseNet: float|null, margin: float|null, warning: bool, reason: string}>
     */
    public static function run(TariffSet $tariffs, ChannelRules $rules, \DateTimeImmutable $now): array
    {
        $calculator = new ShippingCalculator();
        $rows = [];
        foreach (self::SCENARIOS as [$option, $label, $kg, $l, $b, $h]) {
            $item = new Item('pruefung', $label, $kg, $l, $b, $h, unitPriceGross: 1.0);
            $result = $calculator->calculate([$item], new Destination($rules->homeCountry), $rules, $tariffs, $now);
            $o = $result->option($option);
            $fixed = $rules->fixedPrice($option);
            if ($o === null || !$o->available) {
                $rows[] = ['option' => $option, 'label' => $label, 'fixed' => $fixed, 'saleNet' => null, 'purchaseNet' => null, 'margin' => null, 'warning' => false, 'reason' => $o === null ? '' : $o->reason];
                continue;
            }
            $saleNet = round($o->saleCents / 100 / (1 + $rules->vatPercent / 100), 2);
            $purchase = Money::toCents($o->purchaseNet) / 100;
            $rows[] = [
                'option' => $option,
                'label' => $label,
                'fixed' => $fixed,
                'saleNet' => $saleNet,
                'purchaseNet' => $purchase,
                'margin' => round($saleNet - $purchase, 2),
                'warning' => $saleNet < $purchase,
                'reason' => '',
            ];
        }

        return $rows;
    }
}
