<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Ergebnis für eine Versandart (warenpost, kleinpaket, dpd, dhl, spedition, abholung).
 */
final class OptionResult
{
    public const WARENPOST = 'warenpost';
    public const KLEINPAKET = 'kleinpaket';
    public const DPD = 'dpd';
    public const DHL = 'dhl';
    public const FREIGHT = 'spedition';
    public const PICKUP = 'abholung';
    /** Versandkosten auf Anfrage: Bestellung mit 0,00 €, der Shop trägt den Preis nachträglich ein */
    public const REQUEST = 'anfrage';
    /** Zu einer offenen Bestellung beipacken (Preis = Differenz, siehe Beipack) */
    public const BEIPACK = 'beipack';
    /** Lieferung mit der eigenen Tour (Preis und Termin aus Tour\TourPlanner, nicht aus den Tarifen) */
    public const TOUR = 'tour';

    public const ALL = [self::WARENPOST, self::KLEINPAKET, self::DPD, self::DHL, self::FREIGHT, self::PICKUP, self::REQUEST];

    /** Optionen mit echtem Versand (für „günstigste passende“) */
    public const SHIPPING = [self::WARENPOST, self::KLEINPAKET, self::DPD, self::DHL, self::FREIGHT];

    /** Grund-Code, wenn nicht verfügbar: Spedition ohne Preis → „auf Anfrage“ */
    public const REASON_ON_REQUEST = 'onRequest';

    public bool $available = false;

    /** Hinweis für Admin/Log (deutsch), warum nicht verfügbar */
    public string $reason = '';

    public string $reasonCode = '';

    public string $carrier = '';

    public string $carrierName = '';

    public string $productId = '';

    public string $productName = '';

    /** @var list<array<string, mixed>> */
    public array $packages = [];

    /** Einkauf netto in Einheiten (1/10.000 €) */
    public int $purchaseNet = 0;

    /** Verkaufspreis in Cent (brutto in der EU, ohne MwSt. außerhalb) */
    public int $saleCents = 0;

    public bool $vatIncluded = true;

    /** @var list<array{code: string, carrier?: string}> */
    public array $hints = [];

    public ?int $transitMin = null;

    public ?int $transitMax = null;

    public ?\DateTimeImmutable $deliveryFrom = null;

    public ?\DateTimeImmutable $deliveryTo = null;

    /** Günstigste bzw. empfohlene Option */
    public bool $recommended = false;

    public bool $peakApplied = false;

    /** @var array<string, mixed> Zusatzangaben (z. B. Beipack: Grundlage, bereits bezahlt) */
    public array $meta = [];

    public function __construct(public readonly string $key)
    {
    }

    public static function unavailable(string $key, string $reason, string $code = ''): self
    {
        $r = new self($key);
        $r->reason = $reason;
        $r->reasonCode = $code;

        return $r;
    }

    public function hasHint(string $code): bool
    {
        foreach ($this->hints as $hint) {
            if ($hint['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    public function purchaseNetCents(): int
    {
        return Money::toCents($this->purchaseNet);
    }

    /** Zusatzangaben, die der Kunde sehen darf (Beipack: Grundlage und bereits bezahlt) */
    public const PUBLIC_META = ['basedOn', 'combinedSale', 'paid', 'orderNumber'];

    /**
     * Nur Anzeige-Daten für Warenkorb, Versandarten und Store-API – ohne Einkauf, Tarifprodukt,
     * Paketdetails und interne Gründe.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'key' => $this->key,
            'available' => $this->available,
            'reasonCode' => $this->reasonCode,
            'carrierName' => $this->carrierName,
            'packageCount' => \count($this->packages),
            'sale' => $this->saleCents / 100,
            'vatIncluded' => $this->vatIncluded,
            'hints' => array_map(static fn (array $h) => ['code' => (string) $h['code'], 'carrier' => (string) ($h['carrier'] ?? '')], $this->hints),
            'deliveryFrom' => $this->deliveryFrom?->format('Y-m-d'),
            'deliveryTo' => $this->deliveryTo?->format('Y-m-d'),
            'recommended' => $this->recommended,
            'meta' => array_intersect_key($this->meta, array_flip(self::PUBLIC_META)),
        ];
    }

    /**
     * Vollständiges Ergebnis (intern: Bestellung, Admin, Preisprüfung) – nie an den Kunden ausliefern.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'available' => $this->available,
            'reason' => $this->reason,
            'reasonCode' => $this->reasonCode,
            'carrier' => $this->carrier,
            'carrierName' => $this->carrierName,
            'productId' => $this->productId,
            'productName' => $this->productName,
            'packages' => $this->packages,
            'packageCount' => \count($this->packages),
            'purchaseNet' => Money::toCents($this->purchaseNet) / 100,
            'sale' => $this->saleCents / 100,
            'vatIncluded' => $this->vatIncluded,
            'hints' => $this->hints,
            'transitMin' => $this->transitMin,
            'transitMax' => $this->transitMax,
            'deliveryFrom' => $this->deliveryFrom?->format('Y-m-d'),
            'deliveryTo' => $this->deliveryTo?->format('Y-m-d'),
            'recommended' => $this->recommended,
            'meta' => $this->meta,
        ];
    }
}
