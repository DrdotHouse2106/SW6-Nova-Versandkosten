<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * „Zur offenen Bestellung beipacken“ – reine Rechen- und Prüflogik (ohne Shopware).
 *
 * Preis = Versand für (Artikel der alten Bestellung + Warenkorb) mit derselben Versandoption wie die
 * alte Bestellung (geht sie nicht mehr: günstigste passende) MINUS bereits bezahlte Versandkosten,
 * mindestens 0,00 €.
 *
 * Beispiel: alt Kleinpaket 7,50 €, zusammen DPD 7,50 € → 0,00 €; alt Warenpost 5,00 €, zusammen
 * Kleinpaket 7,50 € → 2,50 €.
 */
final class Beipack
{
    /** Bestellungen, die älter sind, werden nicht angeboten (Standard der Einstellung) */
    public const DEFAULT_MAX_AGE_DAYS = 14;

    /** Zahlungsstatus, bei denen die Versandkosten der alten Bestellung als bezahlt bzw. gesichert gelten */
    public const SECURED_PAYMENT_STATES = ['paid', 'paid_partially', 'authorized', 'in_progress'];

    /** Zahlarten, bei denen erst nach dem Versand gezahlt wird (Rechnung, Lastschrift, Nachnahme) */
    public const PAY_LATER_HANDLERS = [
        'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\InvoicePayment',
        'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\DebitPayment',
        'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\CashPayment',
    ];

    /** Zu diesen Versandoptionen der alten Bestellung wird nicht beigepackt (Tour, Abholung) */
    public const EXCLUDED_OPTIONS = [OptionResult::TOUR, OptionResult::PICKUP];

    /**
     * @param ShippingResult $combined Rechenergebnis für alte + neue Artikel (Lieferadresse der alten Bestellung)
     * @param string|null $previousOption Versandoption der alten Bestellung (Zusatzfeld), null = unbekannt
     * @param int $paidCents bereits bezahlte Versandkosten in Cent – gleiche Basis wie der Verkaufspreis
     *                       (in der EU brutto, außerhalb netto)
     */
    public static function quote(ShippingResult $combined, ?string $previousOption, int $paidCents): OptionResult
    {
        $chosen = null;
        if ($previousOption !== null) {
            $option = $combined->option($previousOption);
            if ($option !== null && $option->available && $option->key !== OptionResult::REQUEST) {
                $chosen = $option;
            }
        }
        if ($chosen === null) {
            foreach (OptionResult::SHIPPING as $key) {
                $option = $combined->option($key);
                if ($option === null || !$option->available) {
                    continue;
                }
                if ($chosen === null || $option->saleCents < $chosen->saleCents) {
                    $chosen = $option;
                }
            }
        }
        if ($chosen === null && $previousOption === OptionResult::PICKUP) {
            $chosen = $combined->option(OptionResult::PICKUP);
        }

        if ($chosen === null || !$chosen->available) {
            return OptionResult::unavailable(OptionResult::BEIPACK, 'Für alte und neue Artikel zusammen gibt es keine passende Versandart');
        }

        $result = new OptionResult(OptionResult::BEIPACK);
        $result->available = true;
        $result->carrier = $chosen->carrier;
        $result->carrierName = $chosen->carrierName;
        $result->productId = $chosen->productId;
        $result->productName = $chosen->productName;
        $result->packages = $chosen->packages;
        $result->vatIncluded = $chosen->vatIncluded;
        $result->purchaseNet = $chosen->purchaseNet;
        $result->transitMin = $chosen->transitMin;
        $result->transitMax = $chosen->transitMax;
        $result->deliveryFrom = $chosen->deliveryFrom;
        $result->deliveryTo = $chosen->deliveryTo;
        $result->hints = $chosen->hints;
        $result->saleCents = self::difference($chosen->saleCents, $paidCents);
        $result->meta = [
            'basedOn' => $chosen->key,
            'combinedSale' => round($chosen->saleCents / 100, 2),
            'paid' => round(max(0, $paidCents) / 100, 2),
        ];

        return $result;
    }

    /** Nachzahlung in Cent: Gesamt minus bezahlt, nie negativ. */
    public static function difference(int $combinedCents, int $paidCents): int
    {
        return max(0, $combinedCents - max(0, $paidCents));
    }

    /**
     * Darf zu dieser Bestellung beigepackt werden? Liefert null (ja) oder den Grund (deutsch).
     *
     * @param array{
     *     orderState: string,
     *     deliveryState: string,
     *     orderDate: \DateTimeImmutable,
     *     salesChannelId: string,
     *     country: string,
     *     isBeipack: bool,
     *     option?: string|null,
     *     paymentState?: string|null,
     *     paymentHandler?: string|null
     * } $order
     * @param bool $allowUnpaid Einstellung „auch zu unbezahlten Bestellungen beipacken“
     */
    public static function ineligibleReason(array $order, string $salesChannelId, string $country, \DateTimeImmutable $now, int $maxAgeDays, bool $allowUnpaid = false): ?string
    {
        if (strtolower($order['salesChannelId']) !== strtolower($salesChannelId)) {
            return 'Bestellung gehört zu einem anderen Verkaufskanal';
        }
        if (\in_array($order['orderState'], ['cancelled', 'completed'], true)) {
            return $order['orderState'] === 'cancelled' ? 'Bestellung wurde storniert' : 'Bestellung ist abgeschlossen';
        }
        if ($order['deliveryState'] !== 'open') {
            return 'Bestellung wurde bereits (teilweise) versendet oder storniert';
        }
        if ($order['isBeipack']) {
            return 'Bestellung ist selbst eine Beipack-Bestellung – bitte zur ursprünglichen Bestellung beipacken';
        }
        if ($maxAgeDays > 0 && $order['orderDate'] < $now->modify('-' . $maxAgeDays . ' days')) {
            return \sprintf('Bestellung ist älter als %d Tage', $maxAgeDays);
        }
        if ($country !== '' && strtoupper($order['country']) !== strtoupper($country)) {
            return 'Lieferland weicht von der Bestellung ab';
        }
        if (\in_array($order['option'] ?? null, self::EXCLUDED_OPTIONS, true)) {
            return 'Zu Tour- oder Abholbestellungen kann nicht beigepackt werden';
        }
        if (\array_key_exists('paymentState', $order) && !$allowUnpaid && !self::isPaymentSecured($order['paymentState'] ?? null, $order['paymentHandler'] ?? null)) {
            return 'Bestellung ist noch nicht bezahlt';
        }

        return null;
    }

    /**
     * Gelten die Versandkosten der alten Bestellung als gesichert? Bezahlt/autorisiert – oder eine Zahlart,
     * bei der erst nach dem Versand gezahlt wird. Unbezahlte Vorkasse- oder Online-Bestellungen nicht:
     * würde die alte Bestellung storniert, ginge die Beipack-Bestellung sonst ohne Versandkosten raus.
     */
    public static function isPaymentSecured(?string $paymentState, ?string $paymentHandler): bool
    {
        if ($paymentState !== null && \in_array($paymentState, self::SECURED_PAYMENT_STATES, true)) {
            return true;
        }
        if ($paymentState !== null && \in_array($paymentState, ['cancelled', 'failed', 'refunded', 'refunded_partially', 'chargeback'], true)) {
            return false;
        }

        return $paymentHandler !== null && \in_array(ltrim($paymentHandler, '\\'), self::PAY_LATER_HANDLERS, true);
    }

    /**
     * Bestellkommentar: „BEIPACK zu Bestellung <Nr>“ vorne, Kundenkommentar dahinter.
     */
    public static function comment(string $orderNumber, ?string $customerComment): string
    {
        $prefix = 'BEIPACK zu Bestellung ' . $orderNumber;
        $customerComment = trim((string) $customerComment);
        if ($customerComment === '') {
            return $prefix;
        }
        if (str_starts_with($customerComment, $prefix)) {
            return $customerComment;
        }

        return $prefix . "\n" . $customerComment;
    }
}
