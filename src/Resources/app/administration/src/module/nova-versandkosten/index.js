import { modulePrivilege } from './privilege';
import './component/nova-vk-system-config';
import './page/nova-versandkosten-index';

/**
 * Ein Modul für alles rund um die Versandkosten: Bestellungen → Versandkosten (und als Kachel unter
 * Einstellungen → Versandkosten). Reiter: Übersicht, Einstellungen je Bereich (gleiche Werte wie die
 * Plugin-Konfiguration, Domain NovaVersandkosten.config), Touren, Tarife, Preisprüfung.
 *
 * Route: /nova/versandkosten/index/:tab?/:sub? – die alte Adresse /nova/versandkosten/index (1.0–1.2)
 * bleibt gültig und öffnet die Übersicht.
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
            path: 'index/:tab?/:sub?',
        },
    },

    navigation: [{
        id: 'nova-versandkosten',
        label: 'nova-versandkosten.general.menu',
        color: '#189eff',
        icon: 'regular-truck',
        path: 'nova.versandkosten.index',
        parent: 'sw-order',
        position: 60,
        get privilege() {
            return modulePrivilege();
        },
    }],

    settingsItem: [{
        group: 'commerce',
        to: 'nova.versandkosten.index',
        icon: 'regular-truck',
        label: 'nova-versandkosten.general.menu',
        get privilege() {
            return modulePrivilege();
        },
    }],
});
