<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * „Zu meiner Bestellung beipacken“ gewählt, aber noch keine Bestellung ausgewählt. Blockiert das
 * Bestellen, ohne dass Shopware die Versandart automatisch wechselt (anders als ShippingMethodBlockedError).
 * Text: Snippet „checkout.nova-versandkosten-beipack-select“.
 */
class BeipackSelectionError extends Error
{
    public function __construct()
    {
        parent::__construct('Bitte wählen Sie die Bestellung, zu der beigepackt werden soll.');
    }

    public function getId(): string
    {
        return 'nova-versandkosten-beipack-select';
    }

    public function getMessageKey(): string
    {
        return 'nova-versandkosten-beipack-select';
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
