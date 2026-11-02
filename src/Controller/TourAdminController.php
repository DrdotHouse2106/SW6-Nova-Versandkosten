<?php declare(strict_types=1);

namespace Nova\Versandkosten\Controller;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\Tour\TourDate;
use Nova\Versandkosten\Calculator\Tour\TourInput;
use Nova\Versandkosten\Calculator\Tour\TourPriceProfile;
use Nova\Versandkosten\Service\Settings;
use Nova\Versandkosten\Service\Tour\PostcodeDirectory;
use Nova\Versandkosten\Service\Tour\TourNotificationService;
use Nova\Versandkosten\Service\Tour\TourRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API „Touren“ (Bestellungen → Versandkosten → Touren): Strecken mit Varianten, Tourtermine, Preisprofile (wer
 * fährt), Tour-Lieferungen, Wegpunkt-Suche (PLZ/Ort), Interessenten im Korridor, Benachrichtigung senden,
 * Abonnenten und CSV.
 *
 * Rechte: Lesen nova_vk_tour_route:read / nova_vk_tour_date:read / nova_vk_tour_price_profile:read,
 * Ändern …:create/update/delete,
 * Lieferungen zusätzlich order:read, Abonnenten nova_vk_tour_subscriber:read/delete. Schreiben über die
 * DAL, damit Shopware die Rechte auch dort prüft.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class TourAdminController
{
    /**
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $routes
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $variants
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $dates
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $subscribers
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $profiles
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $routes,
        private readonly EntityRepository $variants,
        private readonly EntityRepository $dates,
        private readonly EntityRepository $subscribers,
        private readonly EntityRepository $profiles,
        private readonly TourRepository $tours,
        private readonly PostcodeDirectory $postcodes,
        private readonly TourNotificationService $notifications,
        private readonly Settings $settings,
    ) {
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/overview', name: 'api.action.nova_versandkosten.tour.overview', defaults: ['_acl' => ['nova_vk_tour_route:read', 'nova_vk_tour_date:read', 'nova_vk_tour_price_profile:read']], methods: ['GET'])]
    public function overview(): JsonResponse
    {
        // Sicherheitsnetz: PLZ-Tabelle leer (z. B. Import beim Update gescheitert) → jetzt laden
        $postcodes = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nova_vk_postcode');
        if ($postcodes === 0) {
            $postcodes = $this->postcodes->import(force: true);
        }

        $routes = [];
        foreach ($this->connection->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, name, start_label, end_label, active, note FROM nova_vk_tour_route ORDER BY name') as $row) {
            $routes[(string) $row['id']] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'startLabel' => (string) ($row['start_label'] ?? ''),
                'endLabel' => (string) ($row['end_label'] ?? ''),
                'active' => (bool) $row['active'],
                'note' => (string) ($row['note'] ?? ''),
                'variants' => [],
            ];
        }
        foreach ($this->connection->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, LOWER(HEX(route_id)) AS route_id, name, position, waypoints FROM nova_vk_tour_variant ORDER BY position, name') as $row) {
            if (isset($routes[(string) $row['route_id']])) {
                $routes[(string) $row['route_id']]['variants'][] = [
                    'id' => (string) $row['id'],
                    'name' => (string) $row['name'],
                    'position' => (int) $row['position'],
                    'waypoints' => TourInput::waypoints(json_decode((string) ($row['waypoints'] ?? ''), true)),
                ];
            }
        }

        $dates = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(d.id)) AS id, LOWER(HEX(d.route_id)) AS route_id, LOWER(HEX(d.variant_id)) AS variant_id,
                    LOWER(HEX(d.price_profile_id)) AS price_profile_id, d.name, d.tour_date, d.order_deadline, d.trailer, d.status, d.waypoints, d.note, d.notified_at, r.name AS route_name, v.name AS variant_name
               FROM nova_vk_tour_date d
               LEFT JOIN nova_vk_tour_route r ON r.id = d.route_id
               LEFT JOIN nova_vk_tour_variant v ON v.id = d.variant_id
              ORDER BY d.tour_date DESC'
        );
        $counts = $this->deliveryCounts();
        $profiles = $this->tours->profiles();
        foreach ($rows as $row) {
            $profileId = \is_string($row['price_profile_id'] ?? null) && isset($profiles[$row['price_profile_id']]) ? $row['price_profile_id'] : null;
            $dates[] = [
                'id' => (string) $row['id'],
                'routeId' => $row['route_id'],
                'routeName' => (string) ($row['route_name'] ?? ''),
                'variantId' => $row['variant_id'],
                'variantName' => (string) ($row['variant_name'] ?? ''),
                'priceProfileId' => $profileId,
                'priceProfileName' => $profileId !== null ? $profiles[$profileId]->name : '',
                'name' => (string) ($row['name'] ?? ''),
                'label' => TourRepository::label($row),
                'tourDate' => (string) $row['tour_date'],
                'orderDeadline' => (string) $row['order_deadline'],
                'trailer' => (bool) $row['trailer'],
                'status' => (string) $row['status'],
                'waypoints' => TourInput::waypoints(json_decode((string) ($row['waypoints'] ?? ''), true)),
                'note' => (string) ($row['note'] ?? ''),
                'notifiedAt' => $row['notified_at'],
                'deliveries' => $counts[(string) $row['id']] ?? 0,
            ];
        }

        $pricing = $this->settings->tourPricing(null);

        return new JsonResponse([
            'routes' => array_values($routes),
            'dates' => $dates,
            'profiles' => array_values(array_map(self::profileArray(...), $profiles)),
            'defaultProfile' => [
                'smallPrice' => $pricing->smallPriceCents / 100,
                'smallMaxKm' => $pricing->smallMaxKm,
                'freightTiers' => TourPriceProfile::tiersToText($pricing->freightTiers),
            ],
            'settings' => [
                'deadlineDays' => max(0, $this->settings->int('tourDeadlineDays', null, TourDate::DEFAULT_DEADLINE_DAYS)),
                'notifyEnabled' => $this->notifications->enabled(null),
                'postcodes' => $postcodes,
                'pricing' => (array) $pricing,
            ],
        ]);
    }

    /** @return array<string, int> */
    private function deliveryCounts(): array
    {
        /** @var array<string, int> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(custom_fields, \'$.nova_versandkosten_tour_termin\')) AS d, COUNT(*)
               FROM `order` WHERE version_id = :live AND JSON_EXTRACT(custom_fields, \'$.nova_versandkosten_tour_termin\') IS NOT NULL
              GROUP BY d',
            ['live' => Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION)]
        );

        return array_map('intval', $rows);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/route', name: 'api.action.nova_versandkosten.tour.route.save', defaults: ['_acl' => ['nova_vk_tour_route:create', 'nova_vk_tour_route:update', 'nova_vk_tour_variant:create', 'nova_vk_tour_variant:update', 'nova_vk_tour_variant:delete']], methods: ['POST'])]
    public function saveRoute(Request $request, Context $context): JsonResponse
    {
        $data = $request->request->all();
        $name = TourInput::text($data['name'] ?? '', 255);
        if ($name === '') {
            return self::error('Bitte einen Namen angeben.');
        }
        $id = \is_string($data['id'] ?? null) && Uuid::isValid($data['id']) ? strtolower($data['id']) : Uuid::randomHex();

        $variants = [];
        $position = 0;
        foreach (\is_array($data['variants'] ?? null) ? $data['variants'] : [] as $variant) {
            if (!\is_array($variant)) {
                continue;
            }
            $waypoints = TourInput::waypoints($variant['waypoints'] ?? []);
            $variantName = TourInput::text($variant['name'] ?? '', 255);
            if (\count($waypoints) < 2) {
                return self::error(\sprintf('Variante „%s“: bitte mindestens zwei Wegpunkte angeben.', $variantName !== '' ? $variantName : (string) ($position + 1)));
            }
            $variants[] = [
                'id' => \is_string($variant['id'] ?? null) && Uuid::isValid($variant['id']) ? strtolower($variant['id']) : Uuid::randomHex(),
                'routeId' => $id,
                'name' => $variantName !== '' ? $variantName : 'Variante ' . ($position + 1),
                'position' => $position++,
                'waypoints' => $waypoints,
            ];
        }
        if ($variants === []) {
            return self::error('Bitte mindestens eine Variante mit Wegpunkten anlegen.');
        }

        $existing = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM nova_vk_tour_variant WHERE route_id = :r', ['r' => Uuid::fromHexToBytes($id)]);
        $removed = array_values(array_diff(array_map('strval', $existing), array_column($variants, 'id')));

        $this->routes->upsert([[
            'id' => $id,
            'name' => $name,
            'startLabel' => TourInput::text($data['startLabel'] ?? '', 255),
            'endLabel' => TourInput::text($data['endLabel'] ?? '', 255),
            'active' => (bool) ($data['active'] ?? true),
            'note' => TourInput::text($data['note'] ?? '', 5000),
        ]], $context);
        $this->variants->upsert($variants, $context);
        if ($removed !== []) {
            $this->variants->delete(array_map(static fn (string $v) => ['id' => $v], $removed), $context);
        }
        $this->tours->reset();

        return new JsonResponse(['id' => $id]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/route/{id}', name: 'api.action.nova_versandkosten.tour.route.delete', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['nova_vk_tour_route:delete', 'nova_vk_tour_variant:delete']], methods: ['DELETE'])]
    public function deleteRoute(string $id, Context $context): Response
    {
        $this->routes->delete([['id' => $id]], $context);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/date', name: 'api.action.nova_versandkosten.tour.date.save', defaults: ['_acl' => ['nova_vk_tour_date:create', 'nova_vk_tour_date:update']], methods: ['POST'])]
    public function saveDate(Request $request, Context $context): JsonResponse
    {
        $data = $request->request->all();
        $routeId = \is_string($data['routeId'] ?? null) && Uuid::isValid($data['routeId']) ? strtolower($data['routeId']) : null;
        $data['routeId'] = $routeId;
        [$clean, $errors] = TourInput::tourDate($data, max(0, $this->settings->int('tourDeadlineDays', null, TourDate::DEFAULT_DEADLINE_DAYS)));
        if ($clean === null) {
            return self::error(implode(' ', $errors));
        }
        $variantId = \is_string($data['variantId'] ?? null) && Uuid::isValid($data['variantId']) ? strtolower($data['variantId']) : null;
        if ($routeId !== null && $variantId !== null) {
            $belongs = $this->connection->fetchOne('SELECT 1 FROM nova_vk_tour_variant WHERE id = :v AND route_id = :r', ['v' => Uuid::fromHexToBytes($variantId), 'r' => Uuid::fromHexToBytes($routeId)]);
            if ($belongs === false) {
                return self::error('Die Variante gehört nicht zur gewählten Strecke.');
            }
        }
        // Eigene Wegpunkte (einmalige Fahrt) haben Vorrang vor der Strecke; Variante nur bei Strecken
        $own = $clean['waypoints'];
        if ($own !== [] && \count($own) < 2) {
            return self::error('Bitte mindestens zwei eigene Wegpunkte angeben.');
        }
        $status = $clean['status'];
        if ($status === TourDate::STATUS_FIXED && $own === [] && $variantId === null) {
            return self::error('Für „festgelegt“ bitte eine Variante wählen.');
        }
        if ($status === TourDate::STATUS_OPEN) {
            $variantId = null;
        }

        // Preisprofil (wer fährt): leer = Standardprofil
        $profileId = \is_string($data['priceProfileId'] ?? null) && Uuid::isValid($data['priceProfileId']) ? strtolower($data['priceProfileId']) : null;
        if ($profileId !== null && !isset($this->tours->profiles()[$profileId])) {
            return self::error('Das gewählte Preisprofil gibt es nicht (mehr).');
        }

        $id = \is_string($data['id'] ?? null) && Uuid::isValid($data['id']) ? strtolower($data['id']) : Uuid::randomHex();
        $this->dates->upsert([[
            'id' => $id,
            'routeId' => $routeId,
            'variantId' => $own === [] ? $variantId : null,
            'priceProfileId' => $profileId,
            'name' => $clean['name'],
            'tourDate' => $clean['tourDate'],
            'orderDeadline' => $clean['orderDeadline'],
            'trailer' => $clean['trailer'],
            'status' => $status,
            'waypoints' => $own === [] ? null : $own,
            'note' => $clean['note'],
        ]], $context);
        $this->tours->reset();

        return new JsonResponse(['id' => $id]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/date/{id}', name: 'api.action.nova_versandkosten.tour.date.delete', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['nova_vk_tour_date:delete']], methods: ['DELETE'])]
    public function deleteDate(string $id, Context $context): Response
    {
        $this->dates->delete([['id' => $id]], $context);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/price-profile', name: 'api.action.nova_versandkosten.tour.price_profile.save', defaults: ['_acl' => ['nova_vk_tour_price_profile:create', 'nova_vk_tour_price_profile:update']], methods: ['POST'])]
    public function saveProfile(Request $request, Context $context): JsonResponse
    {
        $data = $request->request->all();
        [$clean, $errors] = TourInput::priceProfile($data);
        if ($clean === null) {
            return self::error(implode(' ', $errors));
        }
        $id = \is_string($data['id'] ?? null) && Uuid::isValid($data['id']) ? strtolower($data['id']) : Uuid::randomHex();
        $this->profiles->upsert([[
            'id' => $id,
            'name' => $clean['name'],
            'smallPrice' => $clean['smallPriceCents'],
            'smallMaxKm' => $clean['smallMaxKm'],
            'freightTiers' => $clean['freightTiers'],
            'position' => max(0, min(9999, (int) ($data['position'] ?? 0))),
        ]], $context);
        $this->tours->reset();

        return new JsonResponse(['id' => $id]);
    }

    /**
     * Profil löschen: Termine mit diesem Profil fallen auf das Standardprofil zurück.
     */
    #[Route(path: '/api/_action/nova-versandkosten/tour/price-profile/{id}', name: 'api.action.nova_versandkosten.tour.price_profile.delete', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['nova_vk_tour_price_profile:delete', 'nova_vk_tour_date:update']], methods: ['DELETE'])]
    public function deleteProfile(string $id, Context $context): Response
    {
        $this->profiles->delete([['id' => $id]], $context);
        $this->tours->reset();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{id: string, name: string, smallPrice: float, smallMaxKm: float, freightTiers: string, freightTierList: list<array{km: float, price: float}>}
     */
    private static function profileArray(TourPriceProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'smallPrice' => $profile->smallPriceCents / 100,
            'smallMaxKm' => $profile->smallMaxKm,
            'freightTiers' => TourPriceProfile::tiersToText($profile->freightTiers),
            'freightTierList' => array_map(static fn (array $t) => ['km' => $t[0], 'price' => $t[1] / 100], $profile->freightTiers),
        ];
    }

    /**
     * Einmalige Fahrt als Vorlage speichern: neue Strecke mit einer Variante aus den eigenen Wegpunkten.
     */
    #[Route(path: '/api/_action/nova-versandkosten/tour/date/{id}/save-as-route', name: 'api.action.nova_versandkosten.tour.date.save_as_route', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['nova_vk_tour_route:create', 'nova_vk_tour_variant:create']], methods: ['POST'])]
    public function saveAsRoute(string $id, Request $request, Context $context): JsonResponse
    {
        $row = $this->tours->row($id);
        $waypoints = $row === null ? [] : TourInput::waypoints(json_decode((string) ($row['waypoints'] ?? ''), true));
        if (\count($waypoints) < 2) {
            return self::error('Der Termin hat keine eigenen Wegpunkte.');
        }
        $name = TourInput::text($request->request->get('name', ''), 255);
        $first = $waypoints[0];
        $last = $waypoints[\count($waypoints) - 1];
        $routeId = Uuid::randomHex();
        $this->routes->create([[
            'id' => $routeId,
            'name' => $name !== '' ? $name : ($first['label'] . ' – ' . $last['label']),
            'startLabel' => $first['label'],
            'endLabel' => $last['label'],
            'active' => true,
            'variants' => [['id' => Uuid::randomHex(), 'name' => 'Variante 1', 'position' => 0, 'waypoints' => $waypoints]],
        ]], $context);

        return new JsonResponse(['id' => $routeId]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/geocode', name: 'api.action.nova_versandkosten.tour.geocode', defaults: ['_acl' => ['nova_vk_tour_route:read']], methods: ['GET'])]
    public function geocode(Request $request): JsonResponse
    {
        $country = strtoupper((string) $request->query->get('country', 'DE'));

        return new JsonResponse(['results' => $this->postcodes->search((string) $request->query->get('q', ''), preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'DE')]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/date/{id}/deliveries', name: 'api.action.nova_versandkosten.tour.date.deliveries', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['nova_vk_tour_date:read', 'order:read']], methods: ['GET'])]
    public function deliveries(string $id): JsonResponse
    {
        return new JsonResponse(['deliveries' => $this->tours->deliveries($id, $this->postcodes, $this->settings->tourPricing(null))]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/date/{id}/interested', name: 'api.action.nova_versandkosten.tour.date.interested', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['nova_vk_tour_date:read', 'nova_vk_tour_subscriber:read']], methods: ['GET'])]
    public function interested(string $id): JsonResponse
    {
        return new JsonResponse($this->notifications->corridor($id));
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/date/{id}/notify', name: 'api.action.nova_versandkosten.tour.date.notify', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['nova_vk_tour_date:update', 'nova_vk_tour_subscriber:read']], methods: ['POST'])]
    public function notify(string $id): JsonResponse
    {
        $result = $this->notifications->send($id);

        return new JsonResponse($result, $result['error'] !== null ? 400 : 200);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/subscribers', name: 'api.action.nova_versandkosten.tour.subscribers', defaults: ['_acl' => ['nova_vk_tour_subscriber:read']], methods: ['GET'])]
    public function subscribers(Request $request): JsonResponse
    {
        return new JsonResponse(['subscribers' => $this->subscriberList($request)]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/subscribers/csv', name: 'api.action.nova_versandkosten.tour.subscribers.csv', defaults: ['_acl' => ['nova_vk_tour_subscriber:read']], methods: ['GET'])]
    public function subscribersCsv(Request $request): Response
    {
        return new Response(TourNotificationService::csv($this->subscriberList($request)), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="tour-benachrichtigung-' . date('Y-m-d') . '.csv"',
        ]);
    }

    #[Route(path: '/api/_action/nova-versandkosten/tour/subscriber/{id}', name: 'api.action.nova_versandkosten.tour.subscriber.delete', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['nova_vk_tour_subscriber:delete']], methods: ['DELETE'])]
    public function deleteSubscriber(string $id, Context $context): Response
    {
        $this->subscribers->delete([['id' => $id]], $context);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subscriberList(Request $request): array
    {
        $routeId = (string) $request->query->get('routeId', '');
        $radius = $request->query->get('radius');

        return $this->notifications->list(
            Uuid::isValid($routeId) ? $routeId : null,
            is_numeric($radius) && (float) $radius > 0 ? min(500.0, (float) $radius) : null,
            mb_substr((string) $request->query->get('q', ''), 0, 100),
            $request->query->getBoolean('pending', true),
        );
    }

    private static function error(string $message): JsonResponse
    {
        return new JsonResponse(['errors' => [$message]], 400);
    }
}
