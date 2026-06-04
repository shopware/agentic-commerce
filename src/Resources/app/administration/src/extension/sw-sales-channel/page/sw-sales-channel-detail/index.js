import template from './sw-sales-channel-detail.html.twig';
import { coreShipsAgenticCommerce } from '../../../../core-feature';

const { Component, Context, Defaults } = Shopware;
const objectHelper = Shopware.Utils.object;
const ShopwareError = Shopware.Classes.ShopwareError;

/**
 * Exported so unit tests can exercise the override methods directly with a
 * mock `this`; the override registration below is the production wiring.
 */
export const swSalesChannelDetailOverride = {
    template,

    inject: ['systemConfigApiService'],

    provide() {
        return {
            swSalesChannelDetailGetAgenticCommerceExportConfig: () => this.agenticCommerceExportConfig,
        };
    },

    data() {
        return {
            agenticCommerceExportConfig: [],
            previousTemplateName: null,
        };
    },

    watch: {
        'productComparison.templateOptions'(options) {
            if (options?.length) {
                this.detectCurrentTemplate();
            }
        },
        'productExport.bodyTemplate'() {
            this.detectCurrentTemplate();
        },
    },

    computed: {
        isAgenticCommerce() {
            if (!this.salesChannel) {
                return this.$route.params.typeId === Defaults.agenticCommerceTypeId;
            }

            return this.salesChannel.typeId === Defaults.agenticCommerceTypeId;
        },

        // Emit the plugin's own agentic tabs only when core doesn't (avoids
        // duplication). On native cores core emits the tabs; they open this
        // override's (overridden) views.
        shouldRenderAgenticUi() {
            return this.isAgenticCommerce && !coreShipsAgenticCommerce;
        },

        /**
         * Core gates product-export logic on `isProductComparison`. Re-implement
         * the upstream check inline and widen it for agentic commerce so the
         * product-comparison tab/cards render on AC sales channels too —
         * `$super(...)` is unreliable in the 6.5/6.6 range (parent ref may be
         * unresolved during the create flow), so we don't rely on it here.
         */
        isProductComparison() {
            if (this.isAgenticCommerce) {
                return true;
            }

            if (this.salesChannel) {
                return this.salesChannel.typeId === Defaults.productComparisonTypeId;
            }

            return this.$route.params.typeId === Defaults.productComparisonTypeId;
        },

        defaultAgenticCommerceExportConfig() {
            return [
                {
                    provider: 'open-ai',
                    systemConfigDomain: 'SwagAgenticCommerce.openAiProductExport',
                    titleSnippet: 'sw-sales-channel.detail.agenticCommerce.openAiSettingsTitle',
                    positionIdentifier: 'sw-sales-channel-detail-base-agentic-commerce-export-config-provider',
                },
                {
                    provider: 'google',
                    systemConfigDomain: 'SwagAgenticCommerce.googleProductExport',
                    titleSnippet: 'sw-sales-channel.detail.agenticCommerce.googleSettingsTitle',
                    positionIdentifier: 'sw-sales-channel-detail-base-agentic-commerce-export-config-provider',
                },
            ];
        },
    },

    methods: {
        loadEntityData() {
            const hasRouteId = Boolean(this.$route.params.id);
            const hasRouteTypeId = Boolean(this.$route.params.typeId);

            if (!hasRouteId && hasRouteTypeId && this.salesChannel?.id) {
                this.loadAgenticCommerceExportConfig();
                return;
            }

            if (!hasRouteId) {
                return;
            }

            if (hasRouteTypeId) {
                this.loadAgenticCommerceExportConfig();
                return;
            }

            if (this.salesChannel) {
                this.salesChannel = null;
            }

            this.loadSalesChannel();
            this.loadCustomFieldSets();
        },

        loadSalesChannel() {
            this.isLoading = true;
            this.salesChannelRepository
                .get(this.$route.params.id.toLowerCase(), Context.api, this.getLoadSalesChannelCriteria())
                .then((entity) => {
                    this.salesChannel = entity;

                    if (!this.salesChannel.maintenanceIpWhitelist) {
                        this.salesChannel.maintenanceIpWhitelist = [];
                    }

                    this.generateAccessUrl();
                    this.loadAgenticCommerceExportConfig();

                    this.isLoading = false;
                });
        },

        onTemplateSelected(templateName) {
            if (this.productComparison.templates === null || this.productComparison.templates[templateName] === undefined) {
                return;
            }

            this.productComparison.selectedTemplate = { ...this.productComparison.templates[templateName] };
            const contentChanged = Object.keys(this.productComparison.selectedTemplate).some((value) => {
                return this.productExport[value] !== this.productComparison.selectedTemplate[value];
            });

            if (!contentChanged) {
                this.productComparison.templateName = templateName;
                return;
            }

            this.previousTemplateName = this.productComparison.templateName;
            this.productComparison.showTemplateModal = true;
        },

        onTemplateModalClose() {
            this.productComparison.selectedTemplate = null;
            this.productComparison.templateName = this.previousTemplateName ?? null;
            this.previousTemplateName = null;
            this.productComparison.showTemplateModal = false;
        },

        onTemplateModalConfirm() {
            const selectedTemplate = this.productComparison.selectedTemplate;

            Object.keys(selectedTemplate).forEach((key) => {
                if (key === 'providerName') {
                    this.productExport.provider = selectedTemplate[key];
                    return;
                }

                this.productExport[key] = selectedTemplate[key];
            });

            this.productComparison.templateName = selectedTemplate.name ?? null;
            this.productComparison.selectedTemplate = null;
            this.previousTemplateName = null;
            this.productComparison.showTemplateModal = false;

            this.createNotificationInfo({
                message: this.$t('sw-sales-channel.detail.productComparison.templates.message.template-applied-message'),
            });
        },

        async onSave() {
            /**
             * 6.7.9.0+ exposes a dedicated saveSalesChannel(); on 6.7.0–6.7.8.x the
             * save logic is still inlined in onSave(), so fall back to $super and
             * read isSaveSuccessful as the result signal.
             */
            const isLegacyOnSave = typeof this.saveSalesChannel !== 'function';

            /**
             * Snapshot the channel id and the config entries before saving:
             * legacy core onSave triggers loadEntityData() which nulls this.salesChannel
             * and resets agenticCommerceExportConfig before the export-config write runs.
             */
            const channelIdAtSave = this.salesChannel?.id;
            const exportConfigAtSave = this.agenticCommerceExportConfig;

            let saveSuccessful;
            if (isLegacyOnSave) {
                await this.$super('onSave');
                saveSuccessful = this.isSaveSuccessful;
            } else {
                saveSuccessful = await this.saveSalesChannel();
            }

            if (!saveSuccessful) {
                return;
            }

            /**
             * Run AC config validation after the channel saves successfully.
             * This marks required fields in red (field-level highlights) without
             * blocking the channel save — backend validates channel-level fields,
             * frontend validates AC config fields. Both paths produce visible errors
             * consistent with how other channel types behave in Shopware 6.5.
             */
            this.validateAgenticCommerceExportConfig();

            const configSaveSuccessful = await this.saveAgenticCommerceExportConfig(
                channelIdAtSave,
                exportConfigAtSave,
            );

            if (!configSaveSuccessful) {
                return;
            }

            /**
             * v6.7.9.0+ saveSalesChannel does not reload; legacy onSave already did.
             */
            if (!isLegacyOnSave) {
                this.loadEntityData();
            }
        },

        validateAgenticCommerceExportConfig() {
            const requiredError = new ShopwareError({ code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3' });
            const activeProvider = this.productExport?.provider ?? this.defaultAgenticCommerceExportConfig[0]?.provider;
            let isValid = true;

            const activeEntries = this.agenticCommerceExportConfig.filter((entry) => {
                return entry.isLoaded && entry.provider === activeProvider;
            });

            for (const entry of activeEntries) {
                for (const el of entry.elements.filter((el) => el.config?.required && !entry.values[el.name])) {
                    entry.errors[el.name] = requiredError;
                    isValid = false;
                }
            }

            return isValid;
        },

        async loadAgenticCommerceExportConfig() {
            this.agenticCommerceExportConfig = this.defaultAgenticCommerceExportConfig.map((configEntry) => {
                return {
                    ...configEntry,
                    elements: [],
                    values: {},
                    errors: {},
                    isLoading: false,
                    isLoaded: false,
                };
            });

            if (!this.isAgenticCommerce || !this.salesChannel?.id) {
                return;
            }

            await Promise.all(
                this.agenticCommerceExportConfig.map(async (configEntry) => {
                    configEntry.isLoading = true;

                    try {
                        const [
                            config,
                            values,
                        ] = await Promise.all([
                            this.systemConfigApiService.getConfig(configEntry.systemConfigDomain),
                            this.systemConfigApiService.getValues(configEntry.systemConfigDomain, this.salesChannel.id),
                        ]);

                        configEntry.elements = config.flatMap((card) => card.elements);
                        configEntry.values = values;
                        configEntry.isLoaded = true;
                    } catch (_error) {
                        this.createNotificationError({
                            message: this.$t('sw-sales-channel.detail.messageAPIError'),
                        });
                    } finally {
                        configEntry.isLoading = false;
                    }
                }),
            );
        },

        detectCurrentTemplate() {
            if (!this.productComparison.templateOptions?.length || !this.productExport) {
                return;
            }

            const matchedTemplate = this.productComparison.templateOptions.find((template) => {
                return (
                    template.bodyTemplate !== undefined &&
                    template.bodyTemplate === this.productExport.bodyTemplate &&
                    template.headerTemplate === this.productExport.headerTemplate &&
                    template.footerTemplate === this.productExport.footerTemplate
                );
            });

            if (matchedTemplate) {
                this.productComparison.templateName = matchedTemplate.name;
            }
        },

        async saveAgenticCommerceExportConfig(salesChannelIdOverride = null, exportConfigOverride = null) {
            const salesChannelId = salesChannelIdOverride ?? this.salesChannel?.id;
            const exportConfig = exportConfigOverride ?? this.agenticCommerceExportConfig;

            /**
             * When overrides are provided the caller has already established this is an
             * agentic-commerce save; we must not re-check isAgenticCommerce because the
             * live salesChannel may have been nulled by the legacy onSave reload path.
             */
            const isAgenticContext = salesChannelIdOverride !== null
                ? Boolean(salesChannelId)
                : this.isAgenticCommerce && Boolean(salesChannelId);

            if (!isAgenticContext) {
                return true;
            }

            const loadedConfigs = exportConfig.filter((configEntry) => configEntry.isLoaded);

            if (loadedConfigs.length === 0) {
                return true;
            }

            const mergedValues = loadedConfigs.reduce((accumulator, configEntry) => {
                return {
                    ...accumulator,
                    ...objectHelper.deepCopyObject(configEntry.values),
                };
            }, {});

            try {
                await this.systemConfigApiService.batchSave({
                    [salesChannelId]: mergedValues,
                });

                return true;
            } catch (_error) {
                this.createNotificationError({
                    message: this.$t('sw-sales-channel.detail.messageSaveError', {
                        name: this.salesChannel?.name || this.placeholder(this.salesChannel ?? {}, 'name'),
                    }),
                });

                return false;
            }
        },
    },
};

/**
 * overrideIndex 10 places this override after the Storefront bundle's override of
 * the same component (which uses the default index 0). Without it, the two
 * bundles can race in production builds and the Storefront block content can
 * replace the agentic-aware `tab_theme` block defined here.
 */
Component.override('sw-sales-channel-detail', swSalesChannelDetailOverride, 10);
