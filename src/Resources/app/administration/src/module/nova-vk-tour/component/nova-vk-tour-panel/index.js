import template from './nova-vk-tour-panel.html.twig';
import '../../nova-vk-tour.scss';
import { apiGet, apiPost, apiDelete, apiDownload, errorText, formatDate, formatDateTime } from '../../api';

export const TOUR_SUBTABS = ['dates', 'routes', 'profiles', 'deliveries', 'subscribers', 'settings'];

/**
 * Reiter „Touren“ im Versandkosten-Modul (bis 1.2.1 eigene Seite Bestellungen → Touren):
 * Unterreiter Tourtermine, Strecken, Preisprofile (wer fährt), Tour-Lieferungen (Lade-Reihenfolge je
 * Termin), Benachrichtigung (Abonnenten mit Filter und CSV-Export). Den Unterreiter „Einstellungen“
 * füllt die Seite selbst (Karten „Eigene Touren“ und „Tour-Benachrichtigung“ aus der Konfiguration).
 */
Shopware.Component.register('nova-vk-tour-panel', {
    template,

    inject: ['acl'],

    props: {
        sub: {
            type: String,
            required: true,
        },
        canSettings: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            loading: false,
            overview: { routes: [], dates: [], profiles: [], defaultProfile: null, settings: {} },
            profile: null,
            profileSaving: false,
            showPast: false,
            subscribers: [],
            subscribersLoading: false,
            filter: { routeId: '', radius: '', q: '', pending: true },
            deliveryDateId: '',
            deliveryList: [],
            deliveryListLoading: false,
        };
    },

    computed: {
        dates() {
            const today = new Date().toISOString().substring(0, 10);
            return this.overview.dates
                .filter((d) => this.showPast || d.tourDate >= today)
                .sort((a, b) => (this.showPast ? b.tourDate.localeCompare(a.tourDate) : a.tourDate.localeCompare(b.tourDate)));
        },

        /** Termine ab heute (aufsteigend) für den Unterreiter „Tour-Lieferungen“. */
        deliveryDates() {
            const today = new Date().toISOString().substring(0, 10);
            return this.overview.dates
                .filter((d) => d.tourDate >= today)
                .sort((a, b) => a.tourDate.localeCompare(b.tourDate));
        },

        subTabs() {
            return TOUR_SUBTABS.filter((key) => key !== 'settings' || this.canSettings);
        },
    },

    watch: {
        sub: {
            immediate: true,
            handler(sub) {
                if (sub === 'subscribers') {
                    this.loadSubscribers();
                }
                if (sub === 'deliveries') {
                    this.pickDeliveryDate();
                }
            },
        },

        deliveryDateId() {
            this.loadDeliveryList();
        },
    },

    created() {
        this.load();
    },

    methods: {
        formatDate,
        formatDateTime,

        subRoute(sub) {
            return { name: 'nova.versandkosten.index', params: { tab: 'tours', sub } };
        },

        async load() {
            this.loading = true;
            try {
                this.overview = await apiGet('overview');
                if (this.sub === 'deliveries') {
                    this.pickDeliveryDate();
                }
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.loadError')) });
            } finally {
                this.loading = false;
            }
        },

        statusLabel(status) {
            return this.$tc(`nova-vk-tour.status.${status}`);
        },

        editProfile(profile) {
            this.profile = profile
                ? { ...profile }
                : {
                    id: null,
                    name: '',
                    smallPrice: this.overview.defaultProfile?.smallPrice ?? 50,
                    smallMaxKm: this.overview.defaultProfile?.smallMaxKm ?? 10,
                    freightTiers: this.overview.defaultProfile?.freightTiers ?? '',
                    position: (this.overview.profiles || []).length,
                };
        },

        async saveProfile() {
            this.profileSaving = true;
            try {
                await apiPost('price-profile', this.profile);
                this.createNotificationSuccess({ message: this.$tc('nova-vk-tour.general.saved') });
                this.profile = null;
                await this.load();
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.saveError')) });
            } finally {
                this.profileSaving = false;
            }
        },

        async deleteProfile(profile) {
            const used = this.overview.dates.filter((d) => d.priceProfileId === profile.id).length;
            if (!window.confirm(this.$tc('nova-vk-tour.profiles.confirmDelete', 0, { name: profile.name, count: used }))) {
                return;
            }
            try {
                await apiDelete(`price-profile/${profile.id}`);
                if (this.profile && this.profile.id === profile.id) {
                    this.profile = null;
                }
                await this.load();
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.saveError')) });
            }
        },

        formatTiers(text) {
            return String(text || '').split('\n').filter(Boolean).map((line) => {
                const [km, price] = line.split('=');
                return this.$tc('nova-vk-tour.profiles.tierText', 0, { km, price });
            }).join(', ') || this.$tc('nova-vk-tour.profiles.noFreight');
        },

        async loadSubscribers() {
            this.subscribersLoading = true;
            try {
                const data = await apiGet('subscribers', this.subscriberParams());
                this.subscribers = data.subscribers || [];
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.loadError')) });
            } finally {
                this.subscribersLoading = false;
            }
        },

        subscriberParams() {
            const params = { q: this.filter.q, pending: this.filter.pending ? 1 : 0 };
            if (this.filter.routeId) {
                params.routeId = this.filter.routeId;
            }
            if (this.filter.radius) {
                params.radius = this.filter.radius;
            }
            return params;
        },

        exportCsv() {
            apiDownload('subscribers/csv', this.subscriberParams(), `tour-benachrichtigung-${new Date().toISOString().substring(0, 10)}.csv`)
                .catch((error) => this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.loadError')) }));
        },

        async deleteSubscriber(subscriber) {
            if (!window.confirm(this.$tc('nova-vk-tour.subscribers.confirmDelete', 0, { email: subscriber.email }))) {
                return;
            }
            try {
                await apiDelete(`subscriber/${subscriber.id}`);
                this.subscribers = this.subscribers.filter((s) => s.id !== subscriber.id);
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.saveError')) });
            }
        },

        /** Erster Termin ab heute mit Lieferungen, sonst der nächste Termin. */
        pickDeliveryDate() {
            if (this.deliveryDateId && this.deliveryDates.some((d) => d.id === this.deliveryDateId)) {
                return;
            }
            const withDeliveries = this.deliveryDates.find((d) => d.deliveries > 0);
            this.deliveryDateId = (withDeliveries || this.deliveryDates[0] || {}).id || '';
        },

        async loadDeliveryList() {
            if (!this.deliveryDateId) {
                this.deliveryList = [];
                return;
            }
            this.deliveryListLoading = true;
            try {
                const data = await apiGet(`date/${this.deliveryDateId}/deliveries`);
                this.deliveryList = data.deliveries || [];
            } catch (error) {
                this.deliveryList = [];
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.loadError')) });
            } finally {
                this.deliveryListLoading = false;
            }
        },

        dateOption(d) {
            return `${this.formatDate(d.tourDate)} · ${d.label || this.$tc('nova-vk-tour.dates.oneOff')} (${d.deliveries})`;
        },
    },
});
