import template from './sw-order-detail-details.html.twig';

/**
 * Bestellung → Details:
 * - Karte „Versandkosten“ (Dienstleister, Pakete, Einkauf, Verkauf, Differenz; „Maße fehlen“ je nach Einstellung).
 *   Der Einkauf kommt seit 1.4.0 aus GET _action/nova-versandkosten/order/{id} (cost), nicht aus customFields.
 * - Beipack-Hinweise (neue Bestellung: „BEIPACK zu …“, alte Bestellung: Liste der beigepackten Bestellungen)
 * - Karte „Versandkosten auf Anfrage“: Versandkosten eintragen und Kunde benachrichtigen; Hinweis, wenn die
 *   Lieferadresse an einer Strecke oder einem Tourtermin liegt
 * - Eigene Tour: Termin, Variante, Abweichung, Einordnung mit Link zum Termin
 */
Shopware.Component.override('sw-order-detail-details', {
    template,

    inject: ['loginService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            novaVkInfo: null,
            novaVkPrice: '',
            novaVkNotify: true,
            novaVkSaving: false,
        };
    },

    computed: {
        novaVersandkosten() {
            const f = this.order?.customFields || {};
            if (f.nova_versandkosten_sale === undefined && f.nova_versandkosten_dims_missing === undefined) {
                return null;
            }
            // Einkauf seit 1.4.0 aus der eigenen Tabelle (Admin-Route), nicht mehr aus den Zusatzfeldern
            const cost = this.novaVkInfo && this.novaVkInfo.cost ? this.novaVkInfo.cost : null;
            const purchase = Number((cost ? cost.purchaseNet : f.nova_versandkosten_purchase_net) || 0);
            const saleNet = Number(f.nova_versandkosten_sale_net || 0);
            const beipack = f.nova_versandkosten_details?.beipack || null;
            return {
                carrier: f.nova_versandkosten_carrier || '',
                product: f.nova_versandkosten_product || '',
                packages: Array.isArray(f.nova_versandkosten_packages) ? f.nova_versandkosten_packages : [],
                purchase,
                sale: Number(f.nova_versandkosten_sale || 0),
                saleNet,
                difference: Math.round((saleNet - purchase) * 100) / 100,
                dimsMissing: !!f.nova_versandkosten_dims_missing && (this.novaVkInfo ? this.novaVkInfo.showDimsMissing : true),
                delivery: f.nova_versandkosten_delivery || '',
                option: f.nova_versandkosten_option || '',
                beipack,
            };
        },

        novaVkBeipack() {
            const f = this.order?.customFields || {};
            const list = Array.isArray(f.nova_versandkosten_beipack_bestellungen) ? f.nova_versandkosten_beipack_bestellungen : [];
            if (!f.nova_versandkosten_beipack_zu_nr && !list.length) {
                return null;
            }
            return {
                to: f.nova_versandkosten_beipack_zu_nr || null,
                toId: f.nova_versandkosten_beipack_zu || null,
                orders: list,
            };
        },

        novaVkRequest() {
            const f = this.order?.customFields || {};
            if (!f.nova_versandkosten_anfrage) {
                return null;
            }
            return {
                status: f.nova_versandkosten_anfrage_status || 'open',
                pricedAt: f.nova_versandkosten_anfrage_preis_am || null,
            };
        },

        novaVkTour() {
            const f = this.order?.customFields || {};
            if (!f.nova_versandkosten_tour_datum) {
                return null;
            }
            return {
                date: f.nova_versandkosten_tour_datum,
                dateId: f.nova_versandkosten_tour_termin || null,
                variant: f.nova_versandkosten_tour_variante_name || '',
                deviation: f.nova_versandkosten_tour_abweichung_km,
                tier: f.nova_versandkosten_tour_einordnung || '',
            };
        },

        novaVkTourHint() {
            return this.novaVkInfo && Array.isArray(this.novaVkInfo.tourHint) ? this.novaVkInfo.tourHint : [];
        },

        novaVkHttp() {
            return Shopware.Application.getContainer('init').httpClient;
        },
    },

    watch: {
        'order.id': {
            immediate: true,
            handler() {
                this.novaVkLoadInfo();
            },
        },
    },

    methods: {
        novaVkDate(value) {
            if (!value) {
                return '';
            }
            const date = new Date(`${String(value).substring(0, 10)}T12:00:00`);
            return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
        },

        novaVkMoney(value) {
            return Number(value || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        },

        novaVkHeaders() {
            return {
                Accept: 'application/json',
                Authorization: `Bearer ${this.loginService.getToken()}`,
            };
        },

        async novaVkLoadInfo() {
            if (!this.order?.id) {
                return;
            }
            try {
                const response = await this.novaVkHttp.get(`_action/nova-versandkosten/order/${this.order.id}`, { headers: this.novaVkHeaders() });
                this.novaVkInfo = response.data;
                if (this.novaVkRequest && this.novaVkRequest.status === 'priced' && this.novaVkPrice === '') {
                    this.novaVkPrice = String(response.data.shippingTotal ?? '');
                }
            } catch {
                this.novaVkInfo = null;
            }
        },

        async novaVkApply() {
            this.novaVkSaving = true;
            try {
                const response = await this.novaVkHttp.post(`_action/nova-versandkosten/order/${this.order.id}/request-price`, {
                    shippingCosts: this.novaVkPrice,
                    notify: this.novaVkNotify,
                }, { headers: this.novaVkHeaders() });
                this.createNotificationSuccess({
                    message: this.$tc('nova-versandkosten.request.saved', 0, { total: this.novaVkMoney(response.data.total) }),
                });
                if (this.novaVkNotify) {
                    if (response.data.mailSent) {
                        this.createNotificationSuccess({ message: this.$tc('nova-versandkosten.request.mailSent') });
                    } else {
                        this.createNotificationError({ message: this.$tc('nova-versandkosten.request.mailFailed') });
                    }
                }
                // Bestellung neu laden (Summen, Steuern, Zahlung)
                window.location.reload();
            } catch (error) {
                const errors = error?.response?.data?.errors;
                this.createNotificationError({ message: Array.isArray(errors) ? errors.join(' ') : this.$tc('nova-versandkosten.request.error') });
            } finally {
                this.novaVkSaving = false;
            }
        },

        async novaVkResend() {
            this.novaVkSaving = true;
            try {
                const response = await this.novaVkHttp.post(`_action/nova-versandkosten/order/${this.order.id}/request-mail`, {}, { headers: this.novaVkHeaders() });
                if (response.data.ok) {
                    this.createNotificationSuccess({ message: this.$tc('nova-versandkosten.request.mailSent') });
                } else {
                    this.createNotificationError({ message: this.$tc('nova-versandkosten.request.mailFailed') });
                }
            } catch {
                this.createNotificationError({ message: this.$tc('nova-versandkosten.request.mailFailed') });
            } finally {
                this.novaVkSaving = false;
            }
        },
    },
});
