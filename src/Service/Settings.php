<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Nova\Versandkosten\Calculator\ChannelRules;
use Nova\Versandkosten\Calculator\DeliveryEstimator;
use Nova\Versandkosten\Calculator\HolidayCalendar;
use Nova\Versandkosten\Calculator\Item;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Liest die Plugin-Einstellungen eines Verkaufskanals und baut daraus die Kanalregeln des Rechenkerns.
 */
class Settings implements ResetInterface
{
    private const PREFIX = 'NovaVersandkosten.config.';

    /** @var array<string, ChannelRules> */
    private array $rules = [];

    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public function reset(): void
    {
        $this->rules = [];
    }

    public function get(string $key, ?string $salesChannelId): mixed
    {
        return $this->config->get(self::PREFIX . $key, $salesChannelId);
    }

    /** E-Mail-Adresse des Shops (Einstellungen → Stammdaten). */
    public function coreEmail(?string $salesChannelId): string
    {
        $value = $this->config->get('core.basicInformation.email', $salesChannelId);

        return \is_string($value) ? trim($value) : '';
    }

    public function string(string $key, ?string $salesChannelId, string $default = ''): string
    {
        $value = $this->get($key, $salesChannelId);

        return \is_scalar($value) ? trim((string) $value) : $default;
    }

    public function bool(string $key, ?string $salesChannelId, bool $default = false): bool
    {
        $value = $this->get($key, $salesChannelId);

        return $value === null ? $default : (bool) $value;
    }

    public function float(string $key, ?string $salesChannelId, ?float $default = null): ?float
    {
        $value = $this->get($key, $salesChannelId);
        if (\is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) ? (float) $value : $default;
    }

    public function int(string $key, ?string $salesChannelId, int $default = 0): int
    {
        $value = $this->get($key, $salesChannelId);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public function lines(string $key, ?string $salesChannelId): array
    {
        $value = $this->string($key, $salesChannelId);

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []), static fn (string $l) => $l !== ''));
    }

    /** @return list<string> */
    public function ids(string $key, ?string $salesChannelId): array
    {
        $value = $this->get($key, $salesChannelId);

        return \is_array($value) ? array_values(array_map(static fn ($v) => strtolower((string) $v), array_filter($value, 'is_string'))) : [];
    }

    /** @return list<string> */
    public function countries(string $key, ?string $salesChannelId, string $default = ''): array
    {
        $value = $this->string($key, $salesChannelId, $default);

        return array_values(array_filter(array_map(static fn ($c) => strtoupper(trim($c)), preg_split('/[\s,;]+/', $value) ?: []), static fn ($c) => preg_match('/^[A-Z]{2}$/', $c) === 1));
    }

    public function rules(?string $salesChannelId): ChannelRules
    {
        $key = (string) $salesChannelId;
        if (isset($this->rules[$key])) {
            return $this->rules[$key];
        }

        $energy = [];
        foreach ($this->lines('energyPercent', $salesChannelId) as $line) {
            if (preg_match('/^([a-z0-9_\-]+)\s*[=:]\s*([\d.,]+)\s*%?$/i', $line, $m)) {
                $energy[strtolower($m[1])] = (float) str_replace(',', '.', $m[2]);
            }
        }

        $date = function (string $name) use ($salesChannelId): ?\DateTimeImmutable {
            $value = $this->string($name, $salesChannelId);
            if ($value === '') {
                return null;
            }
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        };

        $fallback = $this->float('freightFallbackPrice', $salesChannelId);

        return $this->rules[$key] = new ChannelRules(
            homeCountry: $this->string('homeCountry', $salesChannelId, 'DE') ?: 'DE',
            vatPercent: $this->float('vatPercent', $salesChannelId, 19.0) ?? 19.0,
            fixedPrices: [
                'warenpost' => $this->float('priceWarenpost', $salesChannelId),
                'kleinpaket' => $this->float('priceKleinpaket', $salesChannelId),
                'dpd' => $this->float('priceDpd', $salesChannelId),
                'dhl' => $this->float('priceDhl', $salesChannelId),
            ],
            foreignSurcharge: $this->float('foreignSurcharge', $salesChannelId, 0.0) ?? 0.0,
            passOversize: $this->bool('passOversize', $salesChannelId, true),
            maxPackageKg: $this->float('maxPackageKg', $salesChannelId, 25.0) ?? 25.0,
            goodsValueLimits: array_filter([
                'warenpost' => $this->float('warenpostMaxValue', $salesChannelId),
                'kleinpaket' => $this->float('kleinpaketMaxValue', $salesChannelId),
            ], static fn (?float $v) => $v !== null && $v > 0),
            passPeak: $this->bool('passPeak', $salesChannelId, true),
            peakInPeakFrom: $date('peakInPeakFrom'),
            peakInPeakUntil: $date('peakInPeakUntil'),
            energyPercent: $energy,
            offerDpdOutsideEu: $this->bool('offerDpdOutsideEu', $salesChannelId, true),
            allowedCountries: $this->countries('allowedCountries', $salesChannelId),
            freeCountries: $this->countries('freeCountries', $salesChannelId, 'DE'),
            freightSurcharge: $this->float('freightSurcharge', $salesChannelId, 20.0) ?? 20.0,
            freightFallbackPrice: $fallback !== null && $fallback > 0 ? $fallback : null,
            pickupEnabled: true,
            defaultDims: [
                Item::PARCEL => self::dims($this->string('defaultDimsParcel', $salesChannelId), [30.0, 20.0, 15.0]),
                Item::BULKY => self::dims($this->string('defaultDimsBulky', $salesChannelId), [130.0, 40.0, 40.0]),
                Item::FREIGHT => self::dims($this->string('defaultDimsFreight', $salesChannelId), [120.0, 80.0, 100.0]),
                Item::PICKUP_ONLY => self::dims($this->string('defaultDimsParcel', $salesChannelId), [30.0, 20.0, 15.0]),
            ],
            defaultWeightKg: max(0.01, $this->float('defaultWeight', $salesChannelId, 1.0) ?? 1.0),
            transitTable: ChannelRules::parseTransitTable($this->string('transitTable', $salesChannelId)),
        );
    }

    public function estimator(?string $salesChannelId): ?DeliveryEstimator
    {
        if (!$this->bool('showEstimate', $salesChannelId, true)) {
            return null;
        }
        $state = $this->string('holidayState', $salesChannelId, 'HE');
        $calendar = new HolidayCalendar(
            \in_array($state, HolidayCalendar::STATES, true) ? $state : 'HE',
            HolidayCalendar::parseClosedDays($this->string('closedDays', $salesChannelId))
        );
        $cutoff = $this->string('cutoff', $salesChannelId, '12:00');

        return new DeliveryEstimator(
            $calendar,
            preg_match('/^\d{1,2}:\d{2}$/', $cutoff) ? $cutoff : '12:00',
            max(0, $this->int('processingMin', $salesChannelId, 0)),
            max(0, $this->int('processingMax', $salesChannelId, 1)),
        );
    }

    /**
     * @param array{0: float, 1: float, 2: float} $default
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function dims(string $value, array $default): array
    {
        $parts = preg_split('/\s*[x×*]\s*/u', str_replace(',', '.', trim($value))) ?: [];
        if (\count($parts) !== 3) {
            return $default;
        }
        foreach ($parts as $part) {
            if (!is_numeric($part) || (float) $part <= 0) {
                return $default;
            }
        }

        return [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
    }
}
