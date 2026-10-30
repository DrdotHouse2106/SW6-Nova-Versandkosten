<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Nova\Versandkosten\Calculator\Countries;
use Nova\Versandkosten\Calculator\Destination;
use Nova\Versandkosten\Calculator\FreightQuote;
use Nova\Versandkosten\Calculator\FreightQuoteProvider;
use Nova\Versandkosten\Calculator\Item;
use Nova\Versandkosten\Calculator\Unit;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Speditionspreis über die Cargoboard-API (POST /v1/quotations, Header X-API-KEY).
 *
 * Datenschutz: Übertragen werden nur PLZ und Land von Absender und Empfänger, Gewichte, Maße,
 * Ladungsträger, Warenwert (für Zoll außerhalb der EU) und ob der Empfänger Privatkunde ist –
 * kein Name, keine Straße, keine E-Mail.
 *
 * Antworten werden je Anfrage-Inhalt zwischengespeichert (Standard 30 Minuten), Fehler kurz (2 Minuten),
 * damit ein ausgefallener Dienst die Kasse nicht bei jedem Seitenaufruf ausbremst.
 */
class CargoboardQuoteProvider implements FreightQuoteProvider
{
    public const SANDBOX_URL = 'https://api-sandbox.cargoboard.com';
    public const PRODUCTION_URL = 'https://api.cargoboard.com';

    /** Länder, die Cargoboard laut API annimmt */
    public const COUNTRIES = ['AL', 'AT', 'BE', 'BG', 'CH', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HR', 'HU', 'IE', 'IT', 'LI', 'LT', 'LU', 'LV', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'SE', 'SI', 'SK', 'TR'];

    private ?string $salesChannelId = null;

    private bool $privateCustomer = true;

    public function __construct(
        private readonly Settings $settings,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?ClientInterface $client = null,
    ) {
    }

    public function forChannel(?string $salesChannelId, bool $privateCustomer): self
    {
        $clone = clone $this;
        $clone->salesChannelId = $salesChannelId;
        $clone->privateCustomer = $privateCustomer;

        return $clone;
    }

    public function isConfigured(?string $salesChannelId): bool
    {
        return $this->settings->string('cargoboardApiKey', $salesChannelId) !== '';
    }

    public function quote(Destination $destination, array $units, float $goodsValueGross): FreightQuote
    {
        $sc = $this->salesChannelId;
        $apiKey = $this->settings->string('cargoboardApiKey', $sc);
        if ($apiKey === '') {
            return FreightQuote::notConfigured();
        }
        if (!\in_array($destination->country, self::COUNTRIES, true)) {
            return FreightQuote::error('Land ' . $destination->country . ' wird von Cargoboard nicht angefahren');
        }
        if ($destination->postcode === '') {
            return FreightQuote::error('PLZ fehlt');
        }

        $body = $this->buildBody($destination, $units, $goodsValueGross);
        $sandbox = $this->settings->bool('cargoboardSandbox', $sc, true);
        $cacheKey = 'nova_versandkosten_cb_' . hash('sha256', ($sandbox ? 's' : 'p') . json_encode($body));

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            $cached = $item->get();
            if ($cached instanceof FreightQuote) {
                return $cached;
            }
        }

        $quote = $this->request($body, $apiKey, $sandbox);
        $minutes = $quote->status === FreightQuote::OK ? max(1, $this->settings->int('cargoboardCacheMinutes', $sc, 30)) : 2;
        $item->set($quote);
        $item->expiresAfter($minutes * 60);
        $this->cache->save($item);

        return $quote;
    }

    /**
     * Anfrage-Inhalt (öffentlich für Tests/Prüfung im Admin).
     *
     * @param list<Unit> $units
     *
     * @return array<string, mixed>
     */
    public function buildBody(Destination $destination, array $units, float $goodsValueGross): array
    {
        $sc = $this->salesChannelId;
        $packageType = $this->settings->string('cargoboardPackageType', $sc, 'EP') ?: 'EP';

        // Gleiche Stücke zu einer Zeile zusammenfassen
        $lines = [];
        foreach ($units as $unit) {
            $freight = $unit->item->category === Item::FREIGHT;
            $type = $freight ? $packageType : 'KT';
            $key = $unit->item->id . '|' . $type;
            if (isset($lines[$key])) {
                ++$lines[$key]['unitQuantity'];
                continue;
            }
            $lines[$key] = [
                'content' => $freight ? 'Fahrzeugteile' : 'Fahrzeugteile (Karton)',
                'unitQuantity' => 1,
                'unitPackageType' => $type,
                'unitLength' => (int) ceil($unit->dims[0]),
                'unitWidth' => (int) ceil($unit->dims[1]),
                'unitHeight' => (int) ceil($unit->dims[2]),
                'unitWeight' => round(max(0.1, $unit->weightKg), 1),
            ];
        }

        $consignee = [
            'address' => ['postCode' => $destination->postcode, 'countryCode' => $destination->country],
            'isPrivateCustomer' => $this->privateCustomer && $this->settings->bool('freightPrivateCustomer', $sc, true),
        ];
        if ($consignee['isPrivateCustomer']) {
            // Bei Privatempfängern verlangt Cargoboard eine Zustelloption: Avis vor Zustellung
            $consignee['wantsContactBeforeDelivery'] = true;
        }

        $body = [
            'product' => 'STANDARD',
            'shipper' => ['address' => [
                'postCode' => $this->settings->string('senderPostcode', $sc),
                'countryCode' => strtoupper($this->settings->string('senderCountry', $sc, 'DE') ?: 'DE'),
            ]],
            'consignee' => $consignee,
            'lines' => array_values($lines),
        ];

        if (!Countries::isEu($destination->country)) {
            $body['valueOfGoodsAmount'] = round($goodsValueGross, 2);
            $body['valueOfGoodsCurrency'] = 'EUR';
            $body['isSupplyingCompanyOrReceivingCustomer'] = true;
            if (\in_array($destination->country, ['CH', 'GB'], true)) {
                $body['incoterm'] = 'DAP_UNCLEARED';
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body, string $apiKey, bool $sandbox): FreightQuote
    {
        $client = $this->client ?? new Client();
        $timeout = max(2, $this->settings->int('cargoboardTimeout', $this->salesChannelId, 8));

        try {
            $response = $client->request('POST', ($sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL) . '/v1/quotations', [
                'headers' => ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'],
                'json' => $body,
                'timeout' => $timeout,
                'connect_timeout' => min(5, $timeout),
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Versandkosten: Cargoboard nicht erreichbar', ['error' => $e->getMessage()]);

            return FreightQuote::error('Cargoboard nicht erreichbar');
        }

        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true);
        if ($status !== 200 && $status !== 201) {
            $message = \is_array($json) ? json_encode($json['message'] ?? $json, \JSON_UNESCAPED_UNICODE) : '';
            $this->logger->warning('Nova Versandkosten: Cargoboard-Anfrage abgelehnt', ['status' => $status, 'message' => $message]);

            return FreightQuote::error('Cargoboard HTTP ' . $status);
        }

        return self::parseResponse(\is_array($json) ? $json : []);
    }

    /**
     * @param array<mixed> $json
     */
    public static function parseResponse(array $json): FreightQuote
    {
        $data = \is_array($json['data'] ?? null) ? $json['data'] : [];
        $price = \is_array($data['price'] ?? null) ? $data['price'] : [];
        $amount = $price['amount'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0) {
            return FreightQuote::error('Cargoboard-Antwort ohne Preis');
        }
        $runtime = \is_array($data['runtime'] ?? null) ? $data['runtime'] : [];

        return FreightQuote::ok(
            (float) $amount,
            is_numeric($runtime['daysMin'] ?? null) ? (int) $runtime['daysMin'] : null,
            is_numeric($runtime['daysMax'] ?? null) ? (int) $runtime['daysMax'] : null,
            isset($data['id']) ? (string) $data['id'] : null,
        );
    }
}
