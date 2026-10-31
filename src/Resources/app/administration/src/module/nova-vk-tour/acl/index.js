/**
 * Rollen unter Einstellungen → Benutzer & Rechte → Bestellungen → Touren.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'orders',
    key: 'nova_vk_tour',
    roles: {
        viewer: {
            privileges: [
                'nova_vk_tour_route:read',
                'nova_vk_tour_variant:read',
                'nova_vk_tour_date:read',
                'nova_vk_tour_subscriber:read',
                'nova_vk_tour_price_profile:read',
                'order:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'nova_vk_tour_route:create',
                'nova_vk_tour_route:update',
                'nova_vk_tour_variant:create',
                'nova_vk_tour_variant:update',
                'nova_vk_tour_variant:delete',
                'nova_vk_tour_date:create',
                'nova_vk_tour_date:update',
                'nova_vk_tour_price_profile:create',
                'nova_vk_tour_price_profile:update',
            ],
            dependencies: ['nova_vk_tour.viewer'],
        },
        creator: {
            privileges: [
                'nova_vk_tour_route:create',
                'nova_vk_tour_date:create',
            ],
            dependencies: ['nova_vk_tour.viewer', 'nova_vk_tour.editor'],
        },
        deleter: {
            privileges: [
                'nova_vk_tour_route:delete',
                'nova_vk_tour_variant:delete',
                'nova_vk_tour_date:delete',
                'nova_vk_tour_subscriber:delete',
                'nova_vk_tour_price_profile:delete',
                'nova_vk_tour_date:update',
            ],
            dependencies: ['nova_vk_tour.viewer'],
        },
    },
});
