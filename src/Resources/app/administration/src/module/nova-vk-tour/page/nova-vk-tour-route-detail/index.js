import template from './nova-vk-tour-route-detail.html.twig';
import '../../nova-vk-tour.scss';
import { apiGet, apiPost, apiDelete, errorText } from '../../api';

/**
 * Strecke anlegen/bearbeiten: Name, Start, Ziel, Varianten mit geordneten Wegpunkten.
 */
Shopware.Component.register('nova-vk-tour-route-detail', {
    template,

    inject: ['acl'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            loading: false,
            saving: false,
            route: this.emptyRoute(),
        };
    },

    metaInfo() {
        return { title: this.$createTitle(this.route.name || '') };
    },

    computed: {
        routeId() {
            return this.$route.params.id || null;
        },

        canEdit() {
            return this.acl.can('nova_vk_tour.editor');
        },
    },

    watch: {
        routeId: {
            immediate: true,
            handler() {
                this.load();
            },
        },
    },

    methods: {
        emptyRoute() {
            return { id: null, name: '', startLabel: '', endLabel: '', active: true, note: '', variants: [{ id: null, name: 'Variante A', waypoints: [] }] };
        },

        async load() {
            if (!this.routeId) {
                this.route = this.emptyRoute();
                return;
            }
            this.loading = true;
            try {
                const data = await apiGet('overview');
                const route = (data.routes || []).find((r) => r.id === this.routeId);
                if (route) {
                    this.route = JSON.parse(JSON.stringify(route));
                }
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.loadError')) });
            } finally {
                this.loading = false;
            }
        },

        addVariant() {
            const letter = String.fromCharCode(65 + this.route.variants.length);
            this.route.variants.push({ id: null, name: `Variante ${letter}`, waypoints: [] });
        },

        removeVariant(index) {
            this.route.variants.splice(index, 1);
        },

        async save() {
            this.saving = true;
            try {
                const result = await apiPost('route', this.route);
                this.createNotificationSuccess({ message: this.$tc('nova-vk-tour.general.saved') });
                if (!this.routeId) {
                    this.$router.replace({ name: 'nova.vk.tour.route', params: { id: result.id } });
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
            if (!window.confirm(this.$tc('nova-vk-tour.routes.confirmDelete'))) {
                return;
            }
            try {
                await apiDelete(`route/${this.routeId}`);
                this.$router.push({ name: 'nova.versandkosten.index', params: { tab: 'tours', sub: 'routes' } });
            } catch (error) {
                this.createNotificationError({ message: errorText(error, this.$tc('nova-vk-tour.general.saveError')) });
            }
        },
    },
});
