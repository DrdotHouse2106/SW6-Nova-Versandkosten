import './page/nova-versandkosten-index';

/**
 * Erweiterungen → Nova Versandkosten: Tarifimport (JSON/CSV) und Preisprüfung.
 */
Shopware.Module.register('nova-versandkosten', {
    type: 'plugin',
    name: 'nova-versandkosten',
    title: 'nova-versandkosten.general.title',
    description: 'nova-versandkosten.general.description',
    color: '#189eff',
    icon: 'regular-truck',

    routes: {
        index: {
            component: 'nova-versandkosten-index',
            path: 'index',
            meta: { privilege: 'system.system_config' },
        },
    },

    navigation: [{
        id: 'nova-versandkosten',
        label: 'nova-versandkosten.general.title',
        path: 'nova.versandkosten.index',
        parent: 'sw-extension',
        position: 110,
        privilege: 'system.system_config',
    }],
});
