<?php declare(strict_types=1);

namespace Nova\Versandkosten\Controller;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\PriceCheck;
use Nova\Versandkosten\Calculator\Tariff\TariffCsv;
use Nova\Versandkosten\Calculator\Tariff\TariffException;
use Nova\Versandkosten\Calculator\Tariff\TariffLoader;
use Nova\Versandkosten\Service\Settings;
use Nova\Versandkosten\Service\TariffStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API für „Einstellungen → Erweiterungen → Nova Versandkosten“: Tarifimport (JSON oder CSV),
 * Export und Preisprüfung. Lesen: system_config:read, Import: system_config:update.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AdminController
{
    /** Größte Tarifdatei (Zeichen) – die echte Datei hat einige 100 kB */
    private const MAX_SIZE = 5_000_000;

    public function __construct(
        private readonly TariffStore $store,
        private readonly Settings $settings,
        private readonly Connection $connection,
    ) {
    }

    #[Route(path: '/api/_action/nova-versandkosten/tariffs', name: 'api.action.nova_versandkosten.tariffs', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function status(): JsonResponse
    {
        $set = $this->store->get();
        $products = [];
        foreach ($set === null ? [] : $set->products as $product) {
            $products[] = [
                'id' => $product->id,
                'name' => $product->name,
                'carrier' => $set?->carrierName($product->carrier),
                'option' => $product->option,
                'countries' => \count($product->countries()),
            ];
        }

        return new JsonResponse(['meta' => $this->store->meta(), 'valid' => $set !== null, 'products' => $products]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tariffs', name: 'api.action.nova_versandkosten.tariffs.import', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $content = $request->request->get('content');
        $fileName = (string) $request->request->get('fileName', 'tarife');
        $dryRun = (bool) $request->request->get('dryRun', false);
        if (!\is_string($content) || trim($content) === '') {
            return new JsonResponse(['errors' => ['Keine Datei übergeben.']], 400);
        }
        if (\strlen($content) > self::MAX_SIZE) {
            return new JsonResponse(['errors' => ['Die Datei ist zu groß (höchstens 5 MB).']], 400);
        }

        try {
            $set = TariffLoader::parse($content);
        } catch (TariffException $e) {
            return new JsonResponse(['errors' => \array_slice($e->errors, 0, 100)], 400);
        }

        if (!$dryRun) {
            $this->store->save($set, mb_substr(basename($fileName), 0, 200));
        }

        return new JsonResponse(['ok' => true, 'dryRun' => $dryRun, 'name' => $set->name, 'products' => \count($set->products), 'options' => $set->options(), 'meta' => $this->store->meta()]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tariffs/export', name: 'api.action.nova_versandkosten.tariffs.export', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function export(Request $request): JsonResponse
    {
        $set = $this->store->get();
        if ($set === null) {
            return new JsonResponse(['errors' => ['Noch keine Tarifdatei importiert.']], 404);
        }
        $csv = $request->query->get('format') === 'csv';

        return new JsonResponse([
            'fileName' => 'nova-versandkosten-tarife.' . ($csv ? 'csv' : 'json'),
            'content' => $csv
                ? "\xEF\xBB\xBF" . TariffCsv::fromArray($set->data)
                : json_encode($set->data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/price-check', name: 'api.action.nova_versandkosten.price_check', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function priceCheck(): JsonResponse
    {
        $set = $this->store->get();
        if ($set === null) {
            return new JsonResponse(['channels' => [], 'errors' => ['Noch keine Tarifdatei importiert.']]);
        }

        /** @var array<string, string> $channels */
        $channels = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(sc.id)), COALESCE(MAX(t.name), LOWER(HEX(sc.id)))
               FROM sales_channel sc
               LEFT JOIN sales_channel_translation t ON t.sales_channel_id = sc.id
              WHERE sc.type_id = UNHEX(:storefront)
              GROUP BY sc.id ORDER BY 2',
            ['storefront' => '8a243080f92e4c719546314b577cf82b']
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $out = [];
        foreach ($channels as $id => $name) {
            $rules = $this->settings->rules($id);
            $rows = PriceCheck::run($set, $rules, $now);
            $out[] = [
                'id' => $id,
                'name' => $name,
                'energy' => $rules->energyPercent,
                'warnings' => \count(array_filter($rows, static fn (array $r) => $r['warning'])),
                'rows' => $rows,
            ];
        }

        return new JsonResponse(['channels' => $out, 'date' => $now->format('Y-m-d')]);
    }
}
