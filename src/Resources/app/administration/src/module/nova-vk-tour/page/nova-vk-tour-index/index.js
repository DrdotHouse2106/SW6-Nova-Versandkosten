import template from './nova-vk-tour-index.html.twig';
import '../../nova-vk-tour.scss';
import { apiGet, apiPost, apiDelete, apiDownload, errorText, formatDate, formatDateTime } from '../../api';

/**
 * Übersicht: Tourtermine, Strecken, Preisprofile (wer fährt), Tour-Benachrichtigung (Abonnenten mit
 * Filter und CSV-Export).
 */
Shopware.Component.register('nova-vk-tour-index', {
    template,

    inject: ['acl'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            tab: 'dates',
            loading: false,
            overview: { routes: [], dates: [], profiles: [], defaultProfile: null, settings: {} },
            profile: null,
            profileSaving: false,
            showPast: false,
            subscribers: [],
            subscribersLoading: false,
            filter: { routeId: '', radius: '', q: '', pending: true },
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        dates() {
            const today = new Date().toISOString().substring(0, 10);
            return this.overview.dates
                .filter((d) => this.showPast || d.tourDate >= today)
                .sort((a, b) => (this.showPast ? b.tourDate.localeCompare(a.tourDate) : a.tourDate.localeCompare(b.tourDate)));
        },
    },

    watch: {
        '$route.params.tab': {
            immediate: true,
            handler(tab) {
                this.tab = ['dates', 'routes', 'profiles', 'subscribers'].includes(tab) ? tab : 'dates';
                if (this.tab === 'subscribers') {
                    this.loadSubscribers();
                }
            },
        },
    },

    created() {
        this.load();
    },

    methods: {
        formatDate,
        formatDateTime,

        setTab(tab) {
            this.$router.push({ name: 'nova.vk.tour.index', params: { tab } });
        },

        async load() {
            this.loading = true;
            try {
                this.overview = await apiGet('overview');
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
    },
});
