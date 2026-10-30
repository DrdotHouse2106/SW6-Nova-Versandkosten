<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tariff;

/**
 * Liest eine Tarifdatei als JSON oder CSV (erkannt am ersten Zeichen) und prüft sie.
 */
final class TariffLoader
{
    public static function parse(string $content): TariffSet
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $trimmed = ltrim($content);
        if ($trimmed === '') {
            throw new TariffException(['Die Datei ist leer.']);
        }

        if ($trimmed[0] === '{') {
            return TariffSet::fromJson($trimmed);
        }

        return TariffSet::fromArray(TariffCsv::toArray($content));
    }
}
