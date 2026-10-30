import template from './nova-vk-tour-waypoints.html.twig';
import { apiGet } from '../../api';

/**
 * Geordnete Wegpunkte bearbeiten: Suche nach PLZ oder Ort (mitgelieferte PLZ-Koordinaten), Reihenfolge
 * ändern, Bezeichnung anpassen, entfernen. v-model = Liste {label, postcode, place, lat, lon}.
 */
Shopware.Component.register('nova-vk-tour-waypoints', {
    template,

    props: {
        modelValue: { type: Array, required: false, default: () => [] },
        disabled: { type: Boolean, required: false, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return { query: '', results: [], searching: false, searched: false };
    },

    computed: {
        points() {
            return Array.isArray(this.modelValue) ? this.modelValue : [];
        },
    },

    methods: {
        update(points) {
            this.$emit('update:modelValue', points);
        },

        async search() {
            const q = this.query.trim();
            if (!q) {
                return;
            }
            this.searching = true;
            try {
                const data = await apiGet('geocode', { q });
                this.results = data.results || [];
            } catch {
                this.results = [];
            } finally {
                this.searching = false;
                this.searched = true;
            }
        },

        add(result) {
            this.update([...this.points, {
                label: result.place || result.label,
                postcode: result.postcode,
                place: result.place,
                lat: result.lat,
                lon: result.lon,
            }]);
            this.results = [];
            this.query = '';
            this.searched = false;
        },

        move(index, delta) {
            const target = index + delta;
            if (target < 0 || target >= this.points.length) {
                return;
            }
            const points = [...this.points];
            [points[index], points[target]] = [points[target], points[index]];
            this.update(points);
        },

        remove(index) {
            this.update(this.points.filter((_, i) => i !== index));
        },

        rename(index, label) {
            const points = this.points.map((p, i) => (i === index ? { ...p, label } : p));
            this.update(points);
        },
    },
});
