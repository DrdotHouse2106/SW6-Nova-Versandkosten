<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Tokens und Fristen der Tour-Benachrichtigung (reine Logik, ohne Shopware).
 *
 * - Double-Opt-in: zufälliger Token im Bestätigungslink; in der Datenbank liegt nur sein SHA-256-Hash.
 *   Unbestätigte Anmeldungen verfallen nach DOI_DAYS Tagen.
 * - Abmelden/„weiter benachrichtigen“: Signatur HMAC(Geheimnis der Installation, Abonnent-ID + Aktion) –
 *   gilt dauerhaft, ohne Token zu speichern, und lässt sich nicht für fremde Abonnenten erraten.
 * - Aufbewahrung: aktive Abonnenten ohne Aktivität werden nach RETENTION_MONTHS gelöscht (einstellbar).
 */
final class TourToken
{
    public const DOI_DAYS = 7;
    public const RETENTION_MONTHS = 24;

    public const ACTION_UNSUBSCRIBE = 'unsubscribe';
    public const ACTION_RENEW = 'renew';

    public static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token) === 1;
    }

    public static function sign(string $subscriberId, string $action, string $secret): string
    {
        return substr(hash_hmac('sha256', strtolower($subscriberId) . '|' . $action, $secret), 0, 32);
    }

    public static function verify(string $subscriberId, string $action, string $signature, string $secret): bool
    {
        return $secret !== '' && $signature !== '' && hash_equals(self::sign($subscriberId, $action, $secret), strtolower($signature));
    }

    /** Ist eine unbestätigte Anmeldung abgelaufen? */
    public static function doiExpired(\DateTimeImmutable $createdAt, \DateTimeImmutable $now, int $days = self::DOI_DAYS): bool
    {
        return $createdAt < $now->modify('-' . max(1, $days) . ' days');
    }

    /**
     * Soll ein Abonnent gelöscht werden? pending: nach DOI-Frist; active: nach X Monaten ohne Aktivität
     * (Bestätigung, Umschalten im Konto, „weiter benachrichtigen“). 0 Monate = nie automatisch löschen.
     */
    public static function shouldDelete(string $status, \DateTimeImmutable $createdAt, ?\DateTimeImmutable $lastActivity, \DateTimeImmutable $now, int $retentionMonths = self::RETENTION_MONTHS, int $doiDays = self::DOI_DAYS): bool
    {
        if ($status !== 'active') {
            return self::doiExpired($createdAt, $now, $doiDays);
        }
        if ($retentionMonths <= 0) {
            return false;
        }

        return ($lastActivity ?? $createdAt) < $now->modify('-' . $retentionMonths . ' months');
    }

    /** Einfache Prüfung einer E-Mail-Adresse (Länge, Format) */
    public static function validEmail(string $email): bool
    {
        return mb_strlen($email) <= 254 && filter_var($email, \FILTER_VALIDATE_EMAIL) !== false;
    }
}
