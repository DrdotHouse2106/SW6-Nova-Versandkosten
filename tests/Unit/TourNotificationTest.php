<?php declare(strict_types=1);

namespace Nova\Versandkosten\Tests\Unit;

use Nova\Versandkosten\Calculator\Tour\TourToken;
use Nova\Versandkosten\Calculator\Tour\PostcodeFile;
use PHPUnit\Framework\TestCase;

/**
 * Tour-Benachrichtigung: Double-Opt-in-Token, Abmelde-Signatur, Fristen, PLZ-Datei.
 */
final class TourNotificationTest extends TestCase
{
    public function testDoiToken(): void
    {
        $a = TourToken::random();
        $b = TourToken::random();
        self::assertNotSame($a, $b);
        self::assertTrue(TourToken::isWellFormed($a));
        self::assertFalse(TourToken::isWellFormed('kurz'));
        self::assertFalse(TourToken::isWellFormed($a . '/../x'));
        self::assertSame(64, \strlen(TourToken::hash($a)));
        self::assertNotSame($a, TourToken::hash($a));
        self::assertSame(TourToken::hash($a), TourToken::hash($a));
    }

    public function testUnsubscribeSignature(): void
    {
        $id = '0190a1b2c3d4e5f60718293a4b5c6d7e';
        $sig = TourToken::sign($id, TourToken::ACTION_UNSUBSCRIBE, 'geheim');
        self::assertTrue(TourToken::verify($id, TourToken::ACTION_UNSUBSCRIBE, $sig, 'geheim'));
        self::assertTrue(TourToken::verify(strtoupper($id), TourToken::ACTION_UNSUBSCRIBE, strtoupper($sig), 'geheim'));
        // Andere Aktion, anderer Abonnent, anderes Geheimnis, leere Werte: ungültig
        self::assertFalse(TourToken::verify($id, TourToken::ACTION_RENEW, $sig, 'geheim'));
        self::assertFalse(TourToken::verify('0190a1b2c3d4e5f60718293a4b5c6d7f', TourToken::ACTION_UNSUBSCRIBE, $sig, 'geheim'));
        self::assertFalse(TourToken::verify($id, TourToken::ACTION_UNSUBSCRIBE, $sig, 'anders'));
        self::assertFalse(TourToken::verify($id, TourToken::ACTION_UNSUBSCRIBE, '', 'geheim'));
        self::assertFalse(TourToken::verify($id, TourToken::ACTION_UNSUBSCRIBE, $sig, ''));
    }

    public function testDeadlines(): void
    {
        $now = new \DateTimeImmutable('2026-11-30 12:00');

        // Unbestätigt: nach 7 Tagen löschen
        self::assertFalse(TourToken::doiExpired(new \DateTimeImmutable('2026-11-24 12:00'), $now));
        self::assertTrue(TourToken::doiExpired(new \DateTimeImmutable('2026-11-23 11:00'), $now));
        self::assertTrue(TourToken::shouldDelete('pending', new \DateTimeImmutable('2026-11-20'), null, $now));
        self::assertFalse(TourToken::shouldDelete('pending', new \DateTimeImmutable('2026-11-29'), null, $now));

        // Aktiv: nach 24 Monaten ohne Aktivität löschen, Aktivität verlängert
        self::assertTrue(TourToken::shouldDelete('active', new \DateTimeImmutable('2024-11-01'), new \DateTimeImmutable('2024-11-02'), $now));
        self::assertFalse(TourToken::shouldDelete('active', new \DateTimeImmutable('2024-11-01'), new \DateTimeImmutable('2025-06-01'), $now));
        self::assertFalse(TourToken::shouldDelete('active', new \DateTimeImmutable('2025-01-01'), null, $now));
        // Einstellbar; 0 = nie
        self::assertTrue(TourToken::shouldDelete('active', new \DateTimeImmutable('2025-01-01'), null, $now, 12));
        self::assertFalse(TourToken::shouldDelete('active', new \DateTimeImmutable('2020-01-01'), null, $now, 0));
    }

    public function testEmail(): void
    {
        self::assertTrue(TourToken::validEmail('kunde@example.com'));
        self::assertFalse(TourToken::validEmail('kunde@'));
        self::assertFalse(TourToken::validEmail(str_repeat('a', 250) . '@example.com'));
    }

    public function testPostcodeFile(): void
    {
        self::assertSame('12345', PostcodeFile::normalize(' 12 345 '));
        $rows = PostcodeFile::parse("DE\t10115\tMusterstadt\tLand\t52.5323\t13.3846\nkaputt\nDE\t\tx\ty\t1\t2\n");
        self::assertSame([['DE', '10115', 'Musterstadt', 'Land', 52.5323, 13.3846]], $rows);

        // Mitgelieferte Datei: lesbar, ausreichend groß, Koordinaten in Deutschland
        $all = PostcodeFile::read();
        self::assertGreaterThan(15000, \count($all));
        foreach ($all as $row) {
            self::assertSame('DE', $row[0]);
            self::assertMatchesRegularExpression('/^\d{5}$/', $row[1]);
            self::assertTrue($row[4] > 47.0 && $row[4] < 55.2 && $row[5] > 5.8 && $row[5] < 15.1, $row[1]);
        }
    }
}
