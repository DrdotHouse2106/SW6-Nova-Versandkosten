import template from './nova-versandkosten-index.html.twig';
import './nova-versandkosten-index.scss';

const { Component, Mixin } = Shopware;

/**
 * Tarife importieren (Datei hochladen oder Text einfügen, JSON oder CSV), exportieren und die
 * Festpreise je Verkaufskanal gegen den aktuellen Einkauf prüfen.
 */
Component.register('nova-versandkosten-index', {
    template,

    inject: ['loginService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            tab: 'tariffs',
            isLoading: false,
            status: null,
            content: '',
            fileName: '',
            errors: [],
            check: null,
            checkLoading: false,
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        http() {
            return Shopware.Application.getContainer('init').httpClient;
        },
    },

    created() {
        this.loadStatus();
    },

    methods: {
        headers() {
            return {
                Accept: 'application/json',
                Authorization: `Bearer ${this.loginService.getToken()}`,
            };
        },

        async loadStatus() {
            this.isLoading = true;
            try {
                const response = await this.http.get('_action/nova-versandkosten/tariffs', { headers: this.headers() });
                this.status = response.data;
            } catch (error) {
                this.createNotificationError({ message: this.$tc('nova-versandkosten.tariffs.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        onFile(event) {
            const file = event.target.files && event.target.files[0];
            if (!file) {
                return;
            }
            this.fileName = file.name;
            const reader = new FileReader();
            reader.onload = () => {
                this.content = String(reader.result || '');
            };
            reader.readAsText(file, 'UTF-8');
        },

        async submit(dryRun) {
            this.errors = [];
            if (!this.content.trim()) {
                this.errors = [this.$tc('nova-versandkosten.tariffs.empty')];
                return;
            }
            this.isLoading = true;
            try {
                const response = await this.http.post('_action/nova-versandkosten/tariffs', {
                    content: this.content,
                    fileName: this.fileName || 'eingefuegt',
                    dryRun,
                }, { headers: this.headers() });
                this.createNotificationSuccess({
                    message: this.$tc(dryRun ? 'nova-versandkosten.tariffs.checked' : 'nova-versandkosten.tariffs.saved', 0, {
                        name: response.data.name || '',
                        products: response.data.products,
                    }),
                });
                if (!dryRun) {
                    this.content = '';
                    this.fileName = '';
                    await this.loadStatus();
                }
            } catch (error) {
                this.errors = error?.response?.data?.errors || [this.$tc('nova-versandkosten.tariffs.saveError')];
            } finally {
                this.isLoading = false;
            }
        },

        async download(format) {
            try {
                const response = await this.http.get('_action/nova-versandkosten/tariffs/export', {
                    params: { format },
                    headers: this.headers(),
                });
                const type = format === 'csv' ? 'text/csv;charset=utf-8' : 'application/json';
                const url = window.URL.createObjectURL(new Blob([response.data.content], { type }));
                const link = document.createElement('a');
                link.href = url;
                link.download = response.data.fileName;
                document.body.append(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
            } catch {
                this.createNotificationError({ message: this.$tc('nova-versandkosten.tariffs.loadError') });
            }
        },

        async loadCheck() {
            this.checkLoading = true;
            try {
                const response = await this.http.get('_action/nova-versandkosten/price-check', { headers: this.headers() });
                this.check = response.data;
            } catch {
                this.createNotificationError({ message: this.$tc('nova-versandkosten.check.loadError') });
            } finally {
                this.checkLoading = false;
            }
        },

        setTab(tab) {
            this.tab = tab;
            if (tab === 'check' && !this.check) {
                this.loadCheck();
            }
        },

        money(value) {
            if (value === null || value === undefined) {
                return '–';
            }
            return Number(value).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        },

        formatDate(value) {
            return value ? new Date(value).toLocaleString() : '–';
        },
    },
});
