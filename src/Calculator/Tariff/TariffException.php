<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tariff;

/**
 * Tarifdatei ungültig. Enthält alle gefundenen Fehler (deutsch), damit der Import sie gesammelt anzeigt.
 */
final class TariffException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }
}
