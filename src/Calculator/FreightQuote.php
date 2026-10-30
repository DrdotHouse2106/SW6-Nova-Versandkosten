<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

/**
 * Antwort eines Speditions-Preisdienstes (z. B. Cargoboard): Preis netto in €, Laufzeit in Werktagen.
 */
final class FreightQuote
{
    public const OK = 'ok';
    /** Dienst nicht eingerichtet (kein API-Schlüssel) → „auf Anfrage“ */
    public const NOT_CONFIGURED = 'not_configured';
    /** Dienst hat nicht geantwortet / Fehler → Ersatzpreis oder „auf Anfrage“ */
    public const ERROR = 'error';

    public function __construct(
        public readonly string $status,
        public readonly float $netPrice = 0.0,
        public readonly ?int $daysMin = null,
        public readonly ?int $daysMax = null,
        public readonly string $message = '',
        public readonly ?string $quotationId = null,
    ) {
    }

    public static function ok(float $netPrice, ?int $daysMin = null, ?int $daysMax = null, ?string $quotationId = null): self
    {
        return new self(self::OK, $netPrice, $daysMin, $daysMax, '', $quotationId);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, 0.0, null, null, $message);
    }
}
