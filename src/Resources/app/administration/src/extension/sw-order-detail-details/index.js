import template from './sw-order-detail-details.html.twig';

/**
 * Bestellung → Details: Karte „Versandkosten“ (Dienstleister, Pakete, Einkauf, Verkauf, Differenz).
 */
Shopware.Component.override('sw-order-detail-details', {
    template,

    computed: {
        novaVersandkosten() {
            const f = this.order?.customFields || {};
            if (f.nova_versandkosten_sale === undefined && f.nova_versandkosten_dims_missing === undefined) {
                return null;
            }
            const purchase = Number(f.nova_versandkosten_purchase_net || 0);
            const saleNet = Number(f.nova_versandkosten_sale_net || 0);
            return {
                carrier: f.nova_versandkosten_carrier || '',
                product: f.nova_versandkosten_product || '',
                packages: Array.isArray(f.nova_versandkosten_packages) ? f.nova_versandkosten_packages : [],
                purchase,
                sale: Number(f.nova_versandkosten_sale || 0),
                saleNet,
                difference: Math.round((saleNet - purchase) * 100) / 100,
                dimsMissing: !!f.nova_versandkosten_dims_missing,
                delivery: f.nova_versandkosten_delivery || '',
            };
        },
    },

    methods: {
        novaVkMoney(value) {
            return Number(value || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        },
    },
});
