import template from './sw-sales-channel-detail-base.html.twig';
import { coreShipsAgenticCommerce } from '../../../../core-feature';

const { Component, Defaults } = Shopware;
const objectHelper = Shopware.Utils.object;

/**
 * Exported so unit tests can exercise the override methods directly with a
 * mock `this`; the override registration below is the production wiring.
 */
export const swSalesChannelDetailBaseOverride = {
    template,

    inject: {
        swSalesChannelDetailGetAgenticCommerceExportConfig: {
            from: 'swSalesChannelDetailGetAgenticCommerceExportConfig',
            default: () => [],
        },
    },

    props: {
        agenticCommerceExportConfig: {
            type: Array,
            required: false,
            default: () => [],
        },
    },

    data() {
        return {};
    },

    computed: {
        isAgenticCommerce() {
            return this.salesChannel && this.salesChannel.typeId === Defaults.agenticCommerceTypeId;
        },

        coreShipsAgenticCommerce() {
            return coreShipsAgenticCommerce;
        },

        shouldRenderAgenticUi() {
            return this.isAgenticCommerce && !this.coreShipsAgenticCommerce;
        },

        // Widened to include AC channels so they reuse the product-export blocks.
        // $super() is skipped — it is unreliable during the create flow in 6.5/6.6.
        isProductComparison() {
            if (this.isAgenticCommerce) {
                return true;
            }

            return Boolean(this.salesChannel) && this.salesChannel.typeId === Defaults.productComparisonTypeId;
        },

        resolvedAgenticCommerceExportConfig() {
            let entries = [];

            if (Array.isArray(this.agenticCommerceExportConfig) && this.agenticCommerceExportConfig.length > 0) {
                entries = this.agenticCommerceExportConfig;
            } else if (typeof this.swSalesChannelDetailGetAgenticCommerceExportConfig === 'function') {
                entries = this.swSalesChannelDetailGetAgenticCommerceExportConfig() ?? [];
            }

            if (entries.length === 0) {
                return [];
            }

            const activeProvider = this.productExport?.provider || entries[0]?.provider;
            const filtered = entries.filter((entry) => entry.provider === activeProvider);

            return filtered.length > 0 ? filtered : [entries[0]];
        },

        templateSelectOptions() {
            return this.templateOptions
                .filter((exportTemplate) => {
                    if (this.isAgenticCommerce) {
                        return exportTemplate.salesChannelTypeId === Defaults.agenticCommerceTypeId;
                    }

                    return !exportTemplate.salesChannelTypeId;
                })
                .map((templateOption) => {
                    return {
                        value: templateOption.name,
                        id: templateOption.name,
                        label: this.$t(templateOption.translationKey),
                    };
                });
        },

        disabledCountries() {
            if (this.isAgenticCommerce) {
                return [];
            }

            return this.salesChannel?.countries?.filter(country => country.active === false) ?? [];
        },

        unservedLanguages() {
            if (this.isAgenticCommerce) {
                return [];
            }

            return this.salesChannel?.languages?.filter(
                language => (this.salesChannel.domains?.filter(
                    domain => domain.languageId === language.id,
                ) || []).length === 0,
            ) ?? [];
        },
    },

    async mounted() {
        await this.$nextTick();

        const selectEl = this.$el?.querySelector?.('[data-ac-template-select] select');
        if (selectEl) {
            this._acTemplateSelectHandler = (e) => {
                if (e.target.value) {
                    this.$emit('template-selected', e.target.value);
                }
            };
            selectEl.addEventListener('change', this._acTemplateSelectHandler);
        }
    },

    beforeDestroy() {
        const selectEl = this.$el?.querySelector?.('[data-ac-template-select] select');
        if (selectEl && this._acTemplateSelectHandler) {
            selectEl.removeEventListener('change', this._acTemplateSelectHandler);
        }
    },

    methods: {
        getAgenticCommerceExportElementBind(element) {
            const bind = objectHelper.deepCopyObject(element);

            if (
                [
                    'single-select',
                    'multi-select',
                ].includes(bind.type)
            ) {
                bind.config.labelProperty = 'name';
                bind.config.valueProperty = 'id';
            }

            if (bind.type === 'text-editor') {
                bind.config.componentName = 'sw-text-editor';
            }

            return bind;
        },

        getAgenticCommerceExportCardTitle(configEntry) {
            if (configEntry?.titleSnippet) {
                return this.$t(configEntry.titleSnippet);
            }

            return configEntry?.provider ?? '';
        },

        getAgenticCommerceExportCardPositionIdentifier(configEntry) {
            if (configEntry?.positionIdentifier) {
                return configEntry.positionIdentifier;
            }

            return 'sw-sales-channel-detail-base-agentic-commerce-export-config-provider';
        },

        onAgenticCommerceExportFieldUpdate(configEntry, fieldName, value) {
            if (value instanceof Event) return;
            configEntry.values[fieldName] = value;

            if (configEntry.errors?.[fieldName]) {
                delete configEntry.errors[fieldName];
            }
        },

        onTrackingConfigChange(config) {
            this.salesChannel.configuration = config;
        },
    },
};

Component.override('sw-sales-channel-detail-base', swSalesChannelDetailBaseOverride);
