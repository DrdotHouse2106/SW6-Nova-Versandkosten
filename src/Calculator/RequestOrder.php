<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * „Versandkosten auf Anfrage“ – reine Entscheidungslogik (ohne Shopware), damit sie testbar bleibt.
 */
final class RequestOrder
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PRICED = 'priced';

    /** Shopware-Zahlungs-Handler „Vorkasse“ – Standard, wenn keine Zahlarten eingestellt sind */
    public const PREPAYMENT_HANDLER = 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment';

    /** Zahlungsstatus, bei denen schon (teilweise) Geld geflossen ist oder reserviert wurde */
    public const PAID_STATES = ['paid', 'paid_partially', 'authorized', 'refunded', 'refunded_partially', 'in_progress'];

    /**
     * Welche Zahlarten sind bei „Versandkosten auf Anfrage“ in der Kasse erlaubt?
     *
     * Eingestellte Liste hat Vorrang. Leer = alle Zahlarten mit dem Vorkasse-Handler: Sie ziehen beim
     * Bestellen kein Geld ein. Online-Zahlarten (PayPal, Karte …) würden sofort den Betrag OHNE Versand
     * abbuchen; sie bietet Shopware nach dem Eintragen der Versandkosten über „Zahlungsart ändern“ an
     * (Zahlart-Einstellung „Nach Bestellabschluss änderbar“).
     *
     * @param array<string, string> $methods Zahlart-ID → Handler-Klasse
     * @param list<string> $configured eingestellte Zahlart-IDs
     *
     * @return list<string> erlaubte IDs
     */
    public static function allowedPaymentMethods(array $methods, array $configured): array
    {
        $configured = array_map('strtolower', $configured);
        $allowed = [];
        foreach ($methods as $id => $handler) {
            $id = strtolower((string) $id);
            if ($configured !== [] ? \in_array($id, $configured, true) : ltrim($handler, '\\') === self::PREPAYMENT_HANDLER) {
                $allowed[] = $id;
            }
        }

        return $allowed;
    }

    /**
     * Automatisch stornieren? Nur offene Bestellungen ohne (Teil-)Zahlung, älter als $days Tage; 0 = aus.
     */
    public static function isDueForCancellation(\DateTimeImmutable $orderDate, \DateTimeImmutable $now, int $days, string $orderState, ?string $transactionState): bool
    {
        if ($days <= 0) {
            return false;
        }
        if (!\in_array($orderState, ['open', 'in_progress'], true)) {
            return false;
        }
        if ($transactionState !== null && \in_array($transactionState, self::PAID_STATES, true)) {
            return false;
        }

        return $orderDate < $now->modify('-' . $days . ' days');
    }

    /**
     * Eingabe im Admin ist brutto. Zeigt die Bestellung Nettopreise (B2B), braucht Shopware den Nettobetrag;
     * steuerfreie Bestellungen (Ausland außerhalb der EU) bekommen den Betrag unverändert.
     */
    public static function shippingInput(float $gross, string $taxStatus, float $vatPercent): float
    {
        $gross = max(0.0, $gross);
        if ($taxStatus === 'net') {
            return round($gross / (1 + $vatPercent / 100), 2);
        }

        return round($gross, 2);
    }
}
