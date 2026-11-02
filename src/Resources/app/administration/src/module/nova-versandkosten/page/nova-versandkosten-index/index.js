import template from './nova-versandkosten-index.html.twig';
import './nova-versandkosten-index.scss';
import { SETTINGS_PRIVILEGE, TOUR_PRIVILEGE } from '../../privilege';
import { TOUR_SUBTABS } from '../../../nova-vk-tour/component/nova-vk-tour-panel';
import exampleCsv from '../../../../../../../../../examples/tarife-beispiel.csv?raw';
import exampleJson from '../../../../../../../../../examples/tarife-beispiel.json?raw';
import blankCsv from '../../vorlage/tarife-vorlage.csv?raw';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Reiter des Moduls. `cards` = Namen der Karten aus config.xml (<name>), die der Reiter zeigt.
 * `settings` = braucht das Recht „Einstellungen“, `tours` = braucht das Recht „Touren“.
 */
const TABS = [
    { key: 'overview', settings: true, cards: [] },
    { key: 'prices', settings: true, cards: ['prices'] },
    { key: 'surcharges', settings: true, cards: ['peak', 'energy'] },
    { key: 'countries', settings: true, cards: ['countries', 'deliveryTime'] },
    { key: 'products', settings: true, cards: ['productData'] },
    { key: 'freight', settings: true, cards: ['freight'] },
    { key: 'request', settings: true, cards: ['request', 'beipack', 'tourRequest'] },
    { key: 'tours', tours: true, cards: [] },
    { key: 'tariffs', settings: true, cards: [] },
    { key: 'check', settings: true, cards: ['control'] },
];

const TOUR_SETTINGS_CARDS = ['tours', 'tourNotify'];

const SCHEDULED_TASKS = ['nova_versandkosten.energy_fetch', 'nova_versandkosten.request_cancel', 'nova_versandkosten.tour_cleanup'];

/** Energiezuschlag gilt als aktuell, wenn der letzte Abruf jünger als 36 Stunden und fehlerfrei ist. */
const ENERGY_MAX_AGE_MS = 36 * 3600 * 1000;

const MAX_FILE_BYTES = 5000000;

/**
 * Bestellungen → Versandkosten: ein Modul mit Reitern für Übersicht, Einstellungen (gleiche Werte wie
 * die Plugin-Konfiguration), Touren, Tarife und Preisprüfung. Reiter und Unterreiter stehen in der
 * Adresse (Lesezeichen): /nova/versandkosten/index/<reiter>/<unterreiter>.
 */
Component.register('nova-versandkosten-index', {
    template,

    inject: ['loginService', 'acl', 'repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            // Tarife
            isLoading: false,
            status: null,
            content: '',
            fileName: '',
            fileInfo: '',
            dragging: false,
            errors: [],
            checkResult: null,
            // Preisprüfung
            check: null,
            checkLoading: false,
            // Energiezuschläge
            energy: null,
            energyLoading: false,
            // Übersicht
            overviewLoading: false,
            shippingMethods: null,
            shippingError: false,
            tasks: null,
            tasksError: false,
            tourOverview: null,
            // Einstellungen
            configVisited: false,
            configSaving: false,
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        http() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        canSettings() {
            return this.acl.can(SETTINGS_PRIVILEGE);
        },

        canTours() {
            return this.acl.can(TOUR_PRIVILEGE);
        },

        canEditSettings() {
            return this.acl.can(SETTINGS_PRIVILEGE);
        },

        tabs() {
            return TABS.filter((t) => (t.settings && this.canSettings) || (t.tours && this.canTours));
        },

        tab() {
            const wanted = this.$route.params.tab;
            const found = this.tabs.find((t) => t.key === wanted);
            return found ? found.key : (this.tabs[0] ? this.tabs[0].key : '');
        },

        sub() {
            if (this.tab !== 'tours') {
                return '';
            }
            const allowed = TOUR_SUBTABS.filter((s) => s !== 'settings' || this.canSettings);
            return allowed.includes(this.$route.params.sub) ? this.$route.params.sub : 'dates';
        },

        currentCards() {
            if (!this.canSettings) {
                return [];
            }
            if (this.tab === 'tours') {
                return this.sub === 'settings' ? TOUR_SETTINGS_CARDS : [];
            }
            const found = TABS.find((t) => t.key === this.tab);
            return found ? found.cards : [];
        },

        isSettingsView() {
            return this.currentCards.length > 0;
        },

        priceWarnings() {
            if (!this.check || !Array.isArray(this.check.channels)) {
                return null;
            }
            return this.check.channels.reduce((sum, c) => sum + (c.warnings || 0), 0);
        },

        energyRows() {
            if (!this.energy || !this.energy.series) {
                return [];
            }
            const now = Date.now();
            return ['dhl', 'dpd'].map((key) => {
                const s = this.energy.series[key] || {};
                const mode = this.energy.modes ? this.energy.modes[key] : 'auto';
                const fetchedAt = s.source && s.source.fetchedAt ? new Date(s.source.fetchedAt).getTime() : 0;
                const error = s.source && s.source.error ? s.source.error : '';
                const fresh = mode === 'manual' || (fetchedAt > 0 && now - fetchedAt < ENERGY_MAX_AGE_MS && !error);
                return { key, mode, fetchedAt: s.source ? s.source.fetchedAt : null, error, fresh, total: this.energy.total ? this.energy.total[key] : null };
            });
        },

        activeShippingMethods() {
            return (this.shippingMethods || []).filter((m) => m.active);
        },

        /** Warnungen der Übersicht – jede mit Reiter, der sie behebt. */
        warnings() {
            const list = [];
            if (this.status && !this.status.meta) {
                list.push({ text: this.$tc('nova-versandkosten.overview.warnNoTariffs'), tab: 'tariffs' });
            } else if (this.status && !this.status.valid) {
                list.push({ text: this.$tc('nova-versandkosten.tariffs.invalid'), tab: 'tariffs' });
            }
            this.energyRows.filter((r) => !r.fresh).forEach((r) => {
                list.push({ text: this.$tc('nova-versandkosten.overview.warnEnergy', 0, { carrier: r.key.toUpperCase() }), tab: 'surcharges' });
            });
            if (this.priceWarnings) {
                list.push({ text: this.$tc('nova-versandkosten.overview.warnPrices', this.priceWarnings, { count: this.priceWarnings }), tab: 'check' });
            }
            if (this.shippingMethods && this.activeShippingMethods.length === 0) {
                list.push({ text: this.$tc('nova-versandkosten.overview.warnNoMethods'), link: { name: 'sw.settings.shipping.index' } });
            }
            this.activeShippingMethods.filter((m) => m.salesChannels.length === 0).forEach((m) => {
                list.push({ text: this.$tc('nova-versandkosten.overview.warnNoChannel', 0, { name: m.name }), link: { name: 'sw.settings.shipping.detail', params: { id: m.id } } });
            });
            (this.tasks || []).filter((t) => this.taskProblem(t)).forEach((t) => {
                list.push({ text: this.$tc('nova-versandkosten.overview.warnTask', 0, { name: t.name }) });
            });
            return list;
        },
    },

    watch: {
        $route: {
            immediate: true,
            handler() {
                this.normalizeRoute();
                this.onTabShown();
            },
        },
    },

    created() {
        if (this.canSettings) {
            this.loadStatus();
        }
    },

    methods: {
        headers() {
            return {
                Accept: 'application/json',
                Authorization: `Bearer ${this.loginService.getToken()}`,
            };
        },

        tabRoute(tab) {
            return { name: 'nova.versandkosten.index', params: { tab } };
        },

        /** Adresse ohne/mit unbekanntem Reiter auf den tatsächlich gezeigten Reiter umschreiben. */
        normalizeRoute() {
            if (!this.tab) {
                return;
            }
            const params = { tab: this.tab };
            if (this.tab === 'tours') {
                params.sub = this.sub;
            }
            if (this.$route.params.tab !== params.tab || (params.sub && this.$route.params.sub !== params.sub)) {
                this.$router.replace({ name: 'nova.versandkosten.index', params });
            }
        },

        onTabShown() {
            if (this.isSettingsView) {
                this.configVisited = true;
            }
            if (this.tab === 'overview') {
                this.loadOverview();
            }
            if (this.tab === 'surcharges' && !this.energy) {
                this.loadEnergy();
            }
            if (this.tab === 'check' && !this.check) {
                this.loadCheck();
            }
        },

        // ------------------------------------------------------------ Übersicht

        loadOverview() {
            if (this.overviewLoading) {
                return;
            }
            this.overviewLoading = true;
            const jobs = [this.loadStatus(), this.loadEnergy(), this.loadCheck(), this.loadShippingMethods(), this.loadTasks()];
            if (this.canTours) {
                jobs.push(this.loadTourOverview());
            }
            Promise.all(jobs).finally(() => {
                this.overviewLoading = false;
            });
        },

        async loadShippingMethods() {
            try {
                const criteria = new Criteria(1, 50);
                criteria.addFilter(Criteria.prefix('technicalName', 'nova_versandkosten_'));
                criteria.addAssociation('salesChannels');
                criteria.addSorting(Criteria.sort('position'));
                const result = await this.repositoryFactory.create('shipping_method').search(criteria, Shopware.Context.api);
                this.shippingMethods = Array.from(result).map((m) => ({
                    id: m.id,
                    name: (m.translated && m.translated.name) || m.name || m.technicalName,
                    technicalName: m.technicalName,
                    active: !!m.active,
                    salesChannels: Array.from(m.salesChannels || []).map((sc) => (sc.translated && sc.translated.name) || sc.name),
                }));
                this.shippingError = false;
            } catch {
                this.shippingMethods = null;
                this.shippingError = true;
            }
        },

        async loadTasks() {
            try {
                const criteria = new Criteria(1, 25);
                criteria.addFilter(Criteria.equalsAny('name', SCHEDULED_TASKS));
                const result = await this.repositoryFactory.create('scheduled_task').search(criteria, Shopware.Context.api);
                this.tasks = Array.from(result).map((t) => ({
                    name: t.name,
                    status: t.status,
                    lastExecutionTime: t.lastExecutionTime,
                    nextExecutionTime: t.nextExecutionTime,
                    runInterval: t.runInterval,
                }));
                this.tasksError = false;
            } catch {
                this.tasks = null;
                this.tasksError = true;
            }
        },

        async loadTourOverview() {
            try {
                const response = await this.http.get('_action/nova-versandkosten/tour/overview', { headers: this.headers() });
                this.tourOverview = response.data;
            } catch {
                this.tourOverview = null;
            }
        },

        upcomingTourDates() {
            if (!this.tourOverview || !Array.isArray(this.tourOverview.dates)) {
                return [];
            }
            const today = new Date().toISOString().substring(0, 10);
            return this.tourOverview.dates.filter((d) => d.tourDate >= today && d.status !== 'closed');
        },

        /** Fehlgeschlagen, abgeschaltet oder seit über einem Tag überfällig (Task-Runner läuft nicht). */
        taskProblem(task) {
            if (['failed', 'inactive'].includes(task.status)) {
                return true;
            }
            const next = task.nextExecutionTime ? new Date(task.nextExecutionTime).getTime() : 0;
            return next > 0 && Date.now() - next > 24 * 3600 * 1000;
        },

        taskLabel(name) {
            return this.$tc(`nova-versandkosten.overview.task_${name.replace('nova_versandkosten.', '')}`);
        },

        // ------------------------------------------------------------ Einstellungen

        onSalesChannelChanged(salesChannelId) {
            if (this.$refs.systemConfig) {
                this.$refs.systemConfig.novaVkSetSalesChannel(salesChannelId);
            }
        },

        async saveSettings() {
            if (!this.$refs.systemConfig) {
                return;
            }
            this.configSaving = true;
            try {
                await this.$refs.systemConfig.saveAll();
                this.createNotificationSuccess({ message: this.$tc('nova-versandkosten.settings.saved') });
                this.energy = null;
                this.check = null;
                if (this.tab === 'surcharges') {
                    this.loadEnergy();
                }
                if (this.tab === 'check') {
                    this.loadCheck();
                }
            } catch (error) {
                const errors = error?.response?.data?.errors;
                const detail = Array.isArray(errors) ? errors.map((e) => e.detail || '').join(' ') : '';
                this.createNotificationError({ message: `${this.$tc('nova-versandkosten.settings.saveError')} ${detail}`.trim() });
            } finally {
                this.configSaving = false;
            }
        },

        // ------------------------------------------------------------ Tarife

        async loadStatus() {
            this.isLoading = true;
            try {
                const response = await this.http.get('_action/nova-versandkosten/tariffs', { headers: this.headers() });
                this.status = response.data;
            } catch {
                this.createNotificationError({ message: this.$tc('nova-versandkosten.tariffs.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        chooseFile() {
            if (this.$refs.fileInput) {
                this.$refs.fileInput.click();
            }
        },

        onFile(event) {
            const file = event.target.files && event.target.files[0];
            // Zurücksetzen, damit dieselbe Datei nach einer Änderung erneut gewählt werden kann
            // (sonst feuert „change“ nicht noch einmal).
            event.target.value = '';
            if (file) {
                this.readFile(file);
            }
        },

        onDrop(event) {
            this.dragging = false;
            const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
            if (file) {
                this.readFile(file);
            }
        },

        /**
         * Datei lesen: UTF-8 (mit oder ohne BOM); ist die Datei kein gültiges UTF-8 – Excel speichert
         * „CSV (Trennzeichen-getrennt)“ als Windows-1252 –, wird sie als Windows-1252 gelesen, damit
         * Umlaute stimmen. Danach automatisch prüfen.
         */
        async readFile(file) {
            this.errors = [];
            this.checkResult = null;
            const name = file.name || '';
            if (!/\.(csv|json|txt)$/i.test(name)) {
                this.errors = [this.$tc('nova-versandkosten.tariffs.wrongType', 0, { name })];
                return;
            }
            if (file.size > MAX_FILE_BYTES) {
                this.errors = [this.$tc('nova-versandkosten.tariffs.tooLarge')];
                return;
            }
            try {
                const buffer = await file.arrayBuffer();
                let text;
                let encoding = 'UTF-8';
                try {
                    text = new TextDecoder('utf-8', { fatal: true }).decode(buffer);
                } catch {
                    text = new TextDecoder('windows-1252').decode(buffer);
                    encoding = 'Windows-1252';
                }
                this.content = text.replace(/^﻿/, '');
                this.fileName = name;
                this.fileInfo = this.$tc('nova-versandkosten.tariffs.fileLoaded', 0, {
                    name,
                    size: (file.size / 1024).toLocaleString('de-DE', { maximumFractionDigits: 1 }),
                    encoding,
                });
                await this.submit(true);
            } catch {
                this.errors = [this.$tc('nova-versandkosten.tariffs.readError')];
            }
        },

        clearContent() {
            this.content = '';
            this.fileName = '';
            this.fileInfo = '';
            this.errors = [];
            this.checkResult = null;
        },

        async submit(dryRun) {
            this.errors = [];
            this.checkResult = null;
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
                const message = this.$tc(dryRun ? 'nova-versandkosten.tariffs.checked' : 'nova-versandkosten.tariffs.saved', 0, {
                    name: response.data.name || '',
                    products: response.data.products,
                });
                this.createNotificationSuccess({ message });
                if (dryRun) {
                    this.checkResult = message;
                } else {
                    this.clearContent();
                    await this.loadStatus();
                    this.check = null;
                }
            } catch (error) {
                this.errors = error?.response?.data?.errors || [this.$tc('nova-versandkosten.tariffs.saveError')];
            } finally {
                this.isLoading = false;
            }
        },

        saveFile(text, fileName, type) {
            const url = window.URL.createObjectURL(new Blob([text], { type }));
            const link = document.createElement('a');
            link.href = url;
            link.download = fileName;
            document.body.append(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        },

        async download(format) {
            try {
                const response = await this.http.get('_action/nova-versandkosten/tariffs/export', {
                    params: { format },
                    headers: this.headers(),
                });
                const type = format === 'csv' ? 'text/csv;charset=utf-8' : 'application/json';
                this.saveFile(response.data.content, response.data.fileName, type);
            } catch {
                this.createNotificationError({ message: this.$tc('nova-versandkosten.tariffs.loadError') });
            }
        },

        /** Vorlagen ohne Server: Beispiel (erfundene Platzhalterpreise) oder leere Vorlage. */
        downloadTemplate(kind) {
            if (kind === 'json') {
                this.saveFile(exampleJson, 'tarife-beispiel.json', 'application/json');
            } else if (kind === 'blank') {
                this.saveFile(blankCsv, 'tarife-vorlage.csv', 'text/csv;charset=utf-8');
            } else {
                this.saveFile(exampleCsv, 'tarife-beispiel.csv', 'text/csv;charset=utf-8');
            }
        },

        // ------------------------------------------------------------ Preisprüfung, Energie

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

        async loadEnergy() {
            this.energyLoading = true;
            try {
                const response = await this.http.get('_action/nova-versandkosten/energy', { headers: this.headers() });
                this.energy = response.data;
            } catch {
                this.createNotificationError({ message: this.$tc('nova-versandkosten.energy.loadError') });
            } finally {
                this.energyLoading = false;
            }
        },

        async fetchEnergy() {
            this.energyLoading = true;
            try {
                const response = await this.http.post('_action/nova-versandkosten/energy/fetch', {}, { headers: this.headers() });
                this.energy = response.data;
                const failed = Object.values(this.energy.series || {}).some((s) => s.source && s.source.error);
                if (failed) {
                    this.createNotificationWarning({ message: this.$tc('nova-versandkosten.energy.fetchedWithErrors') });
                } else {
                    this.createNotificationSuccess({ message: this.$tc('nova-versandkosten.energy.fetched') });
                }
            } catch {
                this.createNotificationError({ message: this.$tc('nova-versandkosten.energy.loadError') });
            } finally {
                this.energyLoading = false;
            }
        },

        percent(value) {
            if (value === null || value === undefined) {
                return '–';
            }
            return Number(value).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %';
        },

        components(entry) {
            if (!entry || !entry.components) {
                return '';
            }
            return Object.entries(entry.components).map(([k, v]) => `${k} ${this.percent(v)}`).join(' · ');
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
