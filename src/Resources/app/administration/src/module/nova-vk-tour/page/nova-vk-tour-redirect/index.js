/**
 * Alte Adresse Bestellungen → Touren (/nova/vk/tour/index/:tab?, bis 1.2.1): leitet in das
 * Versandkosten-Modul, Reiter „Touren“ mit dem passenden Unterreiter weiter, damit Lesezeichen
 * weiter funktionieren.
 */
Shopware.Component.register('nova-vk-tour-redirect', {
    template: '<div></div>',

    created() {
        const tab = this.$route.params.tab;
        const sub = ['dates', 'routes', 'profiles', 'subscribers'].includes(tab) ? tab : 'dates';
        this.$router.replace({ name: 'nova.versandkosten.index', params: { tab: 'tours', sub } });
    },
});
