import './acl';
import './component/nova-vk-tour-waypoints';
import './page/nova-vk-tour-index';
import './page/nova-vk-tour-route-detail';
import './page/nova-vk-tour-date-detail';

/**
 * Bestellungen → Touren: Tourtermine, Strecken (Vorlagen mit Varianten), Tour-Lieferungen,
 * Tour-Benachrichtigung (Abonnenten, Versand je Termin).
 */
Shopware.Module.register('nova-vk-tour', {
    type: 'plugin',
    name: 'nova-vk-tour',
    title: 'nova-vk-tour.general.title',
    description: 'nova-vk-tour.general.description',
    color: '#a092f0',
    icon: 'regular-truck',

    routes: {
        index: {
            component: 'nova-vk-tour-index',
            path: 'index/:tab?',
            meta: { privilege: 'nova_vk_tour.viewer' },
        },
        route: {
            component: 'nova-vk-tour-route-detail',
            path: 'route/:id?',
            meta: { parentPath: 'nova.vk.tour.index', privilege: 'nova_vk_tour.viewer' },
        },
        date: {
            component: 'nova-vk-tour-date-detail',
            path: 'date/:id?',
            meta: { parentPath: 'nova.vk.tour.index', privilege: 'nova_vk_tour.viewer' },
        },
    },

    navigation: [{
        id: 'nova-vk-tour',
        label: 'nova-vk-tour.general.title',
        color: '#a092f0',
        path: 'nova.vk.tour.index',
        icon: 'regular-truck',
        parent: 'sw-order',
        position: 60,
        privilege: 'nova_vk_tour.viewer',
    }],
});
