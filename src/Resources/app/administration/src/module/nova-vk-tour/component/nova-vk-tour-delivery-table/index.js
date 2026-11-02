import template from './nova-vk-tour-delivery-table.html.twig';

/**
 * Tour-Lieferungen eines Termins in Lade-Reihenfolge (entlang der Strecke). Genutzt am Tourtermin
 * und im Unterreiter „Tour-Lieferungen“.
 */
Shopware.Component.register('nova-vk-tour-delivery-table', {
    template,

    props: {
        deliveries: {
            type: Array,
            required: true,
        },
    },

    methods: {
        statusText(delivery) {
            return [delivery.orderState, delivery.deliveryState, delivery.paymentState].filter(Boolean).join(' / ');
        },
    },
});
