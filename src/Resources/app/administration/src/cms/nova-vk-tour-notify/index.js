/**
 * Erlebniswelten: Element und Block „Tour-Benachrichtigung“ (Typ „nova-vk-tour-notify“) – Formular
 * E-Mail + PLZ + Einwilligung mit Double-Opt-in. Im Editor nur eine Vorschau; das echte Formular
 * erscheint im Shop, wenn die Tour-Benachrichtigung in den Plugin-Einstellungen eingeschaltet ist.
 */
const preview = `
<div style="padding: 10px; font-size: 12px; line-height: 1.6;">
    <strong>{{ $tc('nova-vk-tour.cms.previewTitle') }}</strong>
    <div style="display: flex; gap: 6px; margin-top: 6px;">
        <span style="flex: 2; border: 1px solid #d1d9e0; border-radius: 3px; padding: 2px 6px; color: #758ca3;">E-Mail</span>
        <span style="flex: 1; border: 1px solid #d1d9e0; border-radius: 3px; padding: 2px 6px; color: #758ca3;">PLZ</span>
    </div>
</div>`;

Shopware.Component.register('sw-cms-el-preview-nova-vk-tour-notify', { template: preview });
Shopware.Component.register('sw-cms-preview-nova-vk-tour-notify', { template: preview });

Shopware.Component.register('sw-cms-el-nova-vk-tour-notify', {
    template: `
<div class="sw-cms-el-nova-vk-tour-notify" style="padding: 16px; border: 1px dashed #d1d9e0;">
    <p v-if="element.config.title.value" style="font-weight: 600; margin-bottom: 6px;">{{ element.config.title.value }}</p>
    <p>{{ element.config.text.value || $tc('nova-vk-tour.cms.defaultText') }}</p>
    <div style="display: flex; gap: 8px; margin-top: 8px;">
        <span style="flex: 2; border: 1px solid #d1d9e0; border-radius: 4px; padding: 6px; color: #758ca3;">{{ $tc('nova-vk-tour.cms.email') }}</span>
        <span style="flex: 1; border: 1px solid #d1d9e0; border-radius: 4px; padding: 6px; color: #758ca3;">{{ $tc('nova-vk-tour.cms.postcode') }}</span>
    </div>
    <p style="margin-top: 8px; color: #758ca3; font-size: 12px;">{{ $tc('nova-vk-tour.cms.hint') }}</p>
</div>`,

    mixins: [Shopware.Mixin.getByName('cms-element')],

    created() {
        this.initElementConfig('nova-vk-tour-notify');
    },
});

Shopware.Component.register('sw-cms-el-config-nova-vk-tour-notify', {
    template: `
<div class="sw-cms-el-config-nova-vk-tour-notify">
    <mt-text-field
        v-model="element.config.title.value"
        maxlength="255"
        :label="$tc('nova-vk-tour.cms.title')"
        @update:model-value="onChange"
    />
    <mt-textarea
        v-model="element.config.text.value"
        maxlength="1000"
        :label="$tc('nova-vk-tour.cms.text')"
        :placeholder="$tc('nova-vk-tour.cms.defaultText')"
        @update:model-value="onChange"
    />
    <p style="color: #758ca3; font-size: 12px;">{{ $tc('nova-vk-tour.cms.hint') }}</p>
</div>`,

    emits: ['element-update'],

    mixins: [Shopware.Mixin.getByName('cms-element')],

    created() {
        this.initElementConfig('nova-vk-tour-notify');
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },
    },
});

Shopware.Component.register('sw-cms-block-nova-vk-tour-notify', {
    template: '<div class="sw-cms-block-nova-vk-tour-notify"><slot name="content"></slot></div>',
});

Shopware.Service('cmsService').registerCmsElement({
    name: 'nova-vk-tour-notify',
    label: 'nova-vk-tour.cms.elementLabel',
    component: 'sw-cms-el-nova-vk-tour-notify',
    configComponent: 'sw-cms-el-config-nova-vk-tour-notify',
    previewComponent: 'sw-cms-el-preview-nova-vk-tour-notify',
    defaultConfig: {
        title: { source: 'static', value: '' },
        text: { source: 'static', value: '' },
    },
});

Shopware.Service('cmsService').registerCmsBlock({
    name: 'nova-vk-tour-notify',
    label: 'nova-vk-tour.cms.blockLabel',
    category: 'form',
    component: 'sw-cms-block-nova-vk-tour-notify',
    previewComponent: 'sw-cms-preview-nova-vk-tour-notify',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: '20px',
        marginRight: '20px',
        sizingMode: 'boxed',
    },
    slots: {
        content: 'nova-vk-tour-notify',
    },
});
