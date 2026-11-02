/**
 * Shopwares sw-system-config (Plugin-Konfiguration) mit Kartenfilter: lädt die komplette config.xml
 * der Domain NovaVersandkosten.config, zeigt aber nur die Karten, deren <name> in `cards` steht.
 *
 * Eine Instanz für alle Einstellungs-Reiter: Beim Reiterwechsel ändert sich nur `cards`, die geladenen
 * und geänderten Werte (je Verkaufskanal) bleiben erhalten; „Speichern“ speichert alle Änderungen
 * aller Reiter. Gespeichert wird wie in der Plugin-Konfiguration in system_config – gleiche Schlüssel,
 * keine Migration.
 */
Shopware.Component.extend('nova-vk-system-config', 'sw-system-config', {
    props: {
        cards: {
            type: Array,
            required: false,
            default: () => [],
        },
    },

    data() {
        return {
            novaVkAllCards: [],
        };
    },

    computed: {
        /** Blendet den Hinweis „Versandkosten-Modul öffnen“ aus (siehe Override sw-system-config). */
        novaVkEmbedded() {
            return true;
        },
    },

    watch: {
        cards() {
            this.novaVkApplyCards();
        },
    },

    methods: {
        async readConfig() {
            await this.$super('readConfig');
            this.novaVkAllCards = Array.isArray(this.config) ? this.config : [];
            this.novaVkApplyCards();
        },

        novaVkApplyCards() {
            const wanted = this.cards || [];
            this.config = this.novaVkAllCards
                .filter((card) => wanted.includes(card.name))
                .sort((a, b) => wanted.indexOf(a.name) - wanted.indexOf(b.name));
        },

        /** Cache-relevante Felder aus allen Karten, nicht nur den gerade sichtbaren. */
        getCacheRelevantFieldNames() {
            const fieldNames = new Set();
            this.novaVkAllCards.forEach((card) => {
                (card.elements || []).forEach((element) => {
                    if (element.config?.cacheRelevant === true) {
                        fieldNames.add(element.name);
                    }
                });
            });

            return fieldNames;
        },

        /** Verkaufskanal von außen setzen (Umschalter oben im Modul). */
        novaVkSetSalesChannel(salesChannelId) {
            this.onSalesChannelChanged(salesChannelId || null);
        },

        /** Gibt es ungespeicherte Änderungen (alle Reiter, alle Verkaufskanäle)? */
        novaVkHasChanges() {
            return this.hasConfigChanges(this.getChangedConfigData());
        },
    },
});
