<?php declare(strict_types=1);

namespace Nova\Versandkosten\Checkout;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Vollständiges Rechenergebnis (Einkauf, Tarifprodukt, Paketpreise, Tarifzeilen, interne Gründe) je
 * Warenkorb – nur im Speicher der laufenden Anfrage.
 *
 * Am Warenkorb selbst hängen seit 1.4.0 nur Anzeige-Daten (Erweiterung „novaVersandkosten“), denn
 * Warenkorb-Erweiterungen liefert die Store-API (GET /store-api/checkout/cart) an jeden Besucher aus.
 * Shopware berechnet den Warenkorb beim Bestellen in derselben Anfrage neu, bevor er ihn in eine
 * Bestellung umwandelt – der OrderSubscriber findet das Ergebnis deshalb hier.
 */
class InternalQuoteStore implements ResetInterface
{
    private const TOUR = '|tour';

    /** @var array<string, array<string, mixed>> Warenkorb-Token → Ergebnis */
    private array $data = [];

    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function set(string $token, array $data): void
    {
        $this->data[$token] = $data;
    }

    public function remove(string $token): void
    {
        unset($this->data[$token], $this->data[$token . self::TOUR]);
    }

    /**
     * Tour: vollständiges Ergebnis (mit Variante und Abweichung je Termin).
     *
     * @param array<string, mixed> $tour
     */
    public function setTour(string $token, array $tour): void
    {
        $this->data[$token . self::TOUR] = $tour;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTour(string $token): ?array
    {
        return $this->data[$token . self::TOUR] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $token): ?array
    {
        return $this->data[$token] ?? null;
    }
}
