import template from './nova-vk-tour-date-detail.html.twig';
import '../../nova-vk-tour.scss';
import { apiGet, apiPost, apiDelete, errorText, formatDate, formatDateTime } from '../../api';

function addDays(isoDate, days) {
    const date = new Date(`${isoDate}T12:00:00`);
    date.setDate(date.getDate() + days);
    return date.toISOString().substring(0, 10);
}

/**
 * Tourtermin anlegen/bearbeiten (Strecke oder eigene Wegpunkte, Datum, Bestellschluss, Hänger, Status,
 * Variante, Preisprofil = wer fährt) mit den Tour-Lieferungen (Lade-Reihenfolge entlang der Strecke) und der Benachrichtigung
 * der Interessenten im Korridor.
 */
Shopware.Component.register('nova-vk-tour-date-detail', {
    template,

    inject: ['acl'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            loading: false,
            saving: false,
            routes: [],
            profiles: [],
            defaultProfile: null,
            settings: { deadlineDays: 2 },
            date: this.emptyDate(),
            deadlineTouched: false,
            deliveries: [],
            deliveriesLoading: false,
            interested: null,
            sending: false,
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        dateId() {
            return this.$route.params.id || null;
        },

        canEdit() {
            return this.acl.can('nova_vk_tour.editor');
        },

        route() {
            return this.routes.find((r) => r.id === this.date.routeId) || null;
        },

        ownWaypoints() {
            return !this.date.routeId;
        },

        profile() {
            return this.profiles.find((p) => p.id === this.date.priceProfileId) || this.defaultProfile;
        },
    },

    watch: {
        dateId: {
            immediate: true,
            handler() {
                this.load();
            },
        },

        'date.routeId'() {
            if (this.date.variantId && !(this.route?.variants || []).some((v) => v.id === this.date.variantId)) {
                this.date.variantId = '';
            }
        },

        'date.tourDate'(value) {
            if (value && !this.deadlineTouched && !this.dateId) {
                this.date.orderDeadline = addDays(value, -Number(this.settings.deadlineDays ?? 2));
            }
        },
    },

    methods: {
        formatDate,
        formatDateTime,

        emptyDate() {
            return { id: null, routeId: '', variantId: '', name: '', tourDate: '', orderDeadline: '', trailer: false, status: 'open', waypoints: [], note: '', priceProfileId: '' };
        },

        async load() {
            this.loading = true;
            try {
                const data = await apiGet('overview');
                this.routes = data.routes || [];
                this.profiles = data.profiles || [];
                this.defaultProfile = data.defaultProfile || null;
                this.settings = data.settings || this.settings;
                const found = this.dateId ? (data.dates || []).find((d) => d.id === this.dateId) : null;
                if (found) {
                    this.date = { ...this.emptyDate(), ...JSON.parse(JSON.stringify(found)), routeId: found.routeId || '', variantId: found.variantId || '', priceProfileId: found.priceProfileId || '' };
                    this.deadlineTouched = true;
                    this.loadDeliveries();
                    this.loadInterested();
                } else {
                    this.date = this.emptyDate();
                    this.deadlineTouched = false;
                    if (this.routes.length === 1) {
                        this.date.routeId = this.routes[0].id;
                    }
                }
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.loadError')) });
            } finally {
                this.loading = false;
            }
        },

        async loadDeliveries() {
            if (!this.dateId) {
                return;
            }
            this.deliveriesLoading = true;
            try {
                const data = await apiGet(`date/${this.dateId}/deliveries`);
                this.deliveries = data.deliveries || [];
            } catch {
                this.deliveries = [];
            } finally {
                this.deliveriesLoading = false;
            }
        },

        async loadInterested() {
            if (!this.dateId || !this.acl.can('nova_vk_tour.viewer')) {
                return;
            }
            try {
                this.interested = await apiGet(`date/${this.dateId}/interested`);
            } catch {
                this.interested = null;
            }
        },

        payload() {
            return {
                ...this.date,
                routeId: this.date.routeId || null,
                variantId: this.date.variantId || null,
                priceProfileId: this.date.priceProfileId || null,
                waypoints: this.ownWaypoints ? this.date.waypoints : [],
            };
        },

        async save() {
            this.saving = true;
            try {
                const result = await apiPost('date', this.payload());
                this.createNotificationSuccess({ message: this.$tc('nova-vk-tour.general.saved') });
                if (!this.dateId) {
                    this.$router.replace({ name: 'nova.vk.tour.date', params: { id: result.id } });
                } else {
                    this.load();
                }
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.saveError')) });
            } finally {
                this.saving = false;
            }
        },

        async remove() {
            if (!window.confirm(this.$tc('nova-vk-tour.dates.confirmDelete'))) {
                return;
            }
            try {
                await apiDelete(`date/${this.dateId}`);
                this.$router.push({ name: 'nova.versandkosten.index', params: { tab: 'tours', sub: 'dates' } });
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.saveError')) });
            }
        },

        async saveAsRoute() {
            const name = window.prompt(this.$tc('nova-vk-tour.dates.saveAsRouteName'), this.date.name || '');
            if (name === null) {
                return;
            }
            try {
                const result = await apiPost(`date/${this.dateId}/save-as-route`, { name });
                this.createNotificationSuccess({ message: this.$tc('nova-vk-tour.dates.savedAsRoute') });
                this.$router.push({ name: 'nova.vk.tour.route', params: { id: result.id } });
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.saveError')) });
            }
        },

        async notify() {
            if (!this.interested || !this.interested.open) {
                return;
            }
            if (!window.confirm(this.$tc('nova-vk-tour.notify.confirm', 0, { count: this.interested.open }))) {
                return;
            }
            this.sending = true;
            try {
                const result = await apiPost(`date/${this.dateId}/notify`);
                this.createNotificationSuccess({ message: this.$tc('nova-vk-tour.notify.sent', 0, { sent: result.sent, failed: result.failed }) });
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.notify.error')) });
            } finally {
                this.sending = false;
                this.loadInterested();
            }
        },

        profileSummary(profile) {
            if (!profile) {
                return '';
            }
            const tiers = String(profile.freightTiers || '').split('\n').filter(Boolean).map((line) => {
                const [km, price] = line.split('=');
                return this.$tc('nova-vk-tour.profiles.tierText', 0, { km, price });
            }).join(', ');
            const small = this.$tc('nova-vk-tour.profiles.smallText', 0, { price: profile.smallPrice, km: profile.smallMaxKm });
            return `${small} · ${this.$tc('nova-vk-tour.profiles.freight')}: ${tiers || this.$tc('nova-vk-tour.profiles.noFreight')}`;
        },
    },
});
