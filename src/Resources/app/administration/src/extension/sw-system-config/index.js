import template from './sw-system-config.html.twig';

/**
 * Plugin-Konfiguration (Erweiterungen → Meine Erweiterungen → Nova Versandkosten → Konfigurieren):
 * Hinweis oben mit Link in das übersichtlichere Versandkosten-Modul. Nur für die eigene Domain und
 * nicht im Modul selbst (dort setzt nova-vk-system-config `novaVkEmbedded`).
 */
Shopware.Component.override('sw-system-config', {
    template,

    computed: {
        novaVkEmbedded() {
            return false;
        },

        novaVkShowModuleHint() {
            return this.domain === 'NovaVersandkosten.config' && !this.novaVkEmbedded;
        },
    },
});
