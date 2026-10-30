<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Nova\Versandkosten\Calculator\OptionResult;

/**
 * Zuordnung Shopware-Versandart ↔ Option des Rechenkerns über den technischen Namen.
 * Die Versandarten legt das Plugin bei der Installation (inaktiv) an; eigene Versandarten lassen sich
 * zuordnen, indem man ihnen in der Administration einen dieser technischen Namen gibt.
 */
final class MethodMap
{
    public const PREFIX = 'nova_versandkosten_';

    /** Option → Standardname (de, en) */
    public const METHODS = [
        OptionResult::WARENPOST => ['Warenpost', 'Warenpost (letter parcel)'],
        OptionResult::KLEINPAKET => ['DHL Kleinpaket', 'DHL small parcel'],
        OptionResult::DPD => ['DPD Paket', 'DPD parcel'],
        OptionResult::DHL => ['DHL Paket', 'DHL parcel'],
        OptionResult::FREIGHT => ['Spedition', 'Freight forwarding'],
        OptionResult::PICKUP => ['Abholung', 'Collection'],
    ];

    public static function technicalName(string $option): string
    {
        return self::PREFIX . $option;
    }

    public static function optionFor(?string $technicalName): ?string
    {
        if ($technicalName === null || !str_starts_with($technicalName, self::PREFIX)) {
            return null;
        }
        $option = substr($technicalName, \strlen(self::PREFIX));

        return isset(self::METHODS[$option]) ? $option : null;
    }
}
