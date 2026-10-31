<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator\Tour;

/**
 * Prüft und bereinigt Eingaben aus der Administration (Strecken, Varianten, Termine).
 */
final class TourInput
{
    public const MAX_WAYPOINTS = 60;

    /**
     * Wegpunkte bereinigen: nur gültige Koordinaten, Texte gekürzt, höchstens MAX_WAYPOINTS.
     *
     * @return list<array{label: string, postcode: string, place: string, lat: float, lon: float}>
     */
    public static function waypoints(mixed $input): array
    {
        $out = [];
        foreach (\is_array($input) ? $input : [] as $waypoint) {
            $point = GeoPoint::fromArray($waypoint);
            if ($point === null || !\is_array($waypoint)) {
                continue;
            }
            $out[] = [
                'label' => self::text($waypoint['label'] ?? '', 120),
                'postcode' => self::text($waypoint['postcode'] ?? '', 20),
                'place' => self::text($waypoint['place'] ?? '', 120),
                'lat' => round($point->lat, 5),
                'lon' => round($point->lon, 5),
            ];
            if (\count($out) >= self::MAX_WAYPOINTS) {
                break;
            }
        }

        return $out;
    }

    public static function text(mixed $value, int $max): string
    {
        return \is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $max) : '';
    }

    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($m[1] . '-' . $m[2] . '-' . $m[3]);
    }

    /**
     * Termin prüfen. Fehlt der Bestellschluss, gilt der Standard (X Tage vorher); liegt er nach dem
     * Termin, wird er auf den Termin begrenzt.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array{tourDate: string, orderDeadline: string, status: string, trailer: bool, name: string, note: string, waypoints: list<array{label: string, postcode: string, place: string, lat: float, lon: float}>}|null, 1: list<string>}
     */
    public static function tourDate(array $input, int $deadlineDays = TourDate::DEFAULT_DEADLINE_DAYS): array
    {
        $errors = [];
        $date = self::date($input['tourDate'] ?? null);
        if ($date === null) {
            $errors[] = 'Bitte ein gültiges Datum angeben.';
        }
        $deadline = self::date($input['orderDeadline'] ?? null);
        $status = \is_string($input['status'] ?? null) && \in_array($input['status'], TourDate::STATUSES, true) ? $input['status'] : TourDate::STATUS_OPEN;
        $waypoints = self::waypoints($input['waypoints'] ?? []);
        $routeId = $input['routeId'] ?? null;
        if ((!\is_string($routeId) || $routeId === '') && \count($waypoints) < 2) {
            $errors[] = 'Bitte eine Strecke wählen oder mindestens zwei eigene Wegpunkte angeben.';
        }
        if ($date === null || $errors !== []) {
            return [null, $errors];
        }
        $deadline ??= TourDate::defaultDeadline($date, $deadlineDays);
        if ($deadline > $date) {
            $deadline = $date;
        }

        return [[
            'tourDate' => $date->format('Y-m-d'),
            'orderDeadline' => $deadline->format('Y-m-d'),
            'status' => $status,
            'trailer' => (bool) ($input['trailer'] ?? false),
            'name' => self::text($input['name'] ?? '', 255),
            'note' => self::text($input['note'] ?? '', 5000),
            'waypoints' => $waypoints,
        ], []];
    }

    /**
     * Preisprofil prüfen: Name, Preis Kleinteile/Paket/Sperrgut in Euro (Komma erlaubt), Höchstabweichung
     * in km, Speditionsstufen als Text („30=190“ je Zeile) oder Liste [[km, Cent], …]. Leere Stufen =
     * keine Speditionsware mit diesem Profil.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array{name: string, smallPriceCents: int, smallMaxKm: float, freightTiers: list<array{0: float, 1: int}>}|null, 1: list<string>}
     */
    public static function priceProfile(array $input): array
    {
        $errors = [];
        $name = self::text($input['name'] ?? '', 255);
        if ($name === '') {
            $errors[] = 'Bitte einen Namen angeben.';
        }
        $price = self::decimal($input['smallPrice'] ?? null);
        if ($price === null || $price < 0 || $price > 100000) {
            $errors[] = 'Bitte einen gültigen Preis für Kleinteile/Paket/Sperrgut angeben.';
        }
        $maxKm = self::decimal($input['smallMaxKm'] ?? null);
        if ($maxKm === null || $maxKm < 0 || $maxKm > 1000) {
            $errors[] = 'Bitte eine gültige Höchstabweichung (km) für Kleinteile/Paket/Sperrgut angeben.';
        }
        $tiersInput = $input['freightTiers'] ?? '';
        if (\is_array($tiersInput)) {
            $tiers = TourPriceProfile::tiersFromArray($tiersInput);
        } else {
            $text = self::text($tiersInput, 2000);
            $tiers = TourPricing::parseTiers($text, false);
            $lines = array_filter(array_map('trim', preg_split('/[\r\n]+/', $text) ?: []), static fn (string $l) => $l !== '' && !str_starts_with($l, '#'));
            if (\count($tiers) !== \count($lines)) {
                $errors[] = 'Speditionsstufen: bitte je Zeile „bis km = Preis“ angeben, z. B. „30=190“.';
            }
        }
        foreach ($tiers as [$km]) {
            if ($km > 1000) {
                $errors[] = 'Speditionsstufen: höchstens 1000 km.';
                break;
            }
        }
        if ($errors !== [] || $price === null || $maxKm === null) {
            return [null, $errors];
        }

        return [[
            'name' => $name,
            'smallPriceCents' => (int) round($price * 100),
            'smallMaxKm' => round($maxKm, 2),
            'freightTiers' => \array_slice($tiers, 0, 20),
        ], []];
    }

    /** Zahl aus Eingabe (Komma oder Punkt als Dezimalzeichen) – null, wenn ungültig */
    public static function decimal(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (!\is_string($value) || preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*$/', $value, $m) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $m[1]);
    }
}
