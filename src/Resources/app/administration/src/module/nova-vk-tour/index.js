import './acl';
import './component/nova-vk-tour-waypoints';
import './component/nova-vk-tour-delivery-table';
import './component/nova-vk-tour-panel';
import './page/nova-vk-tour-redirect';
import './page/nova-vk-tour-route-detail';
import './page/nova-vk-tour-date-detail';

/**
 * Eigene Touren. Die Listen (Tourtermine, Strecken, Preisprofile, Tour-Lieferungen, Benachrichtigung)
 * stehen seit 1.3.0 im Versandkosten-Modul (Bestellungen → Versandkosten → Touren); hier bleiben die
 * Detailseiten für Strecke und Tourtermin sowie die Weiterleitung der alten Adresse
 * Bestellungen → Touren. Kein eigener Menüeintrag mehr.
 */
Shopware.Module.register('nova-vk-tour', {
    type: 'plugin',
    name: 'nova-vk-tour',
    title: 'nova-vk-tour.general.title',
    description: 'nova-vk-tour.general.description',
    color: '#189eff',
    icon: 'regular-truck',

    routes: {
        index: {
            component: 'nova-vk-tour-redirect',
            path: 'index/:tab?',
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
});
