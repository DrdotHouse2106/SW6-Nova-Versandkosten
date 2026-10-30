<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * „Lieferung mit unserer Tour“ gewählt, aber kein (gültiger) Termin ausgewählt. Blockiert das Bestellen,
 * ohne die Versandart zu wechseln. Texte: Snippets „checkout.nova-versandkosten-tour-select“ bzw.
 * „checkout.nova-versandkosten-tour-invalid“ (gewählter Termin inzwischen nicht mehr buchbar).
 */
class TourSelectionError extends Error
{
    public function __construct(private readonly bool $invalid = false)
    {
        parent::__construct($invalid
            ? 'Der gewählte Tourtermin ist nicht mehr verfügbar. Bitte wählen Sie einen anderen Termin.'
            : 'Bitte wählen Sie den Termin für die Lieferung mit unserer Tour.');
    }

    public function getId(): string
    {
        return $this->getMessageKey();
    }

    public function getMessageKey(): string
    {
        return $this->invalid ? 'nova-versandkosten-tour-invalid' : 'nova-versandkosten-tour-select';
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function isPersistent(): bool
    {
        return false;
    }

    public function getParameters(): array
    {
        return [];
    }
}
