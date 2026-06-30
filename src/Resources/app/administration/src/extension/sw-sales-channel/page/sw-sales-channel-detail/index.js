import template from './sw-sales-channel-detail.html.twig';
import './sw-sales-channel-detail.scss';
import { coreShipsAgenticCommerce } from '../../../../core-feature';
import {
    defaultForm as ucpDefaultForm,
    normalizeConfig as ucpNormalizeConfig,
    buildConfigPayload as ucpBuildConfigPayload,
} from '../../agentic-commerce/ucp-form-state';
import { extractApiErrorMessage } from '../../agentic-commerce/error-message.util';

const { Component, Context, Defaults } = Shopware;
const objectHelper = Shopware.Utils.object;
const ShopwareError = Shopware.Classes.ShopwareError;

/**
 * Exported so unit tests can exercise the override methods directly with a
 * mock `this`; the override registration below is the production wiring.
 */
export const swSalesChannelDetailOverride = {
    template,

    inject: ['systemConfigApiService', 'ucpAdminApiService'],

    provide() {
        return {
            swSalesChannelDetailGetAgenticCommerceExportConfig: () => this.agenticCommerceExportConfig,
            // The UCP form state is owned by the page (not the tab view) so edits
            // survive tab switches and persist through the page's global Save —
            // mirroring how agenticCommerceExportConfig is handled.
            swSalesChannelGetUcpState: () => this.ucpState,
        };
    },

    data() {
        return {
            agenticCommerceExportConfig: [],
            previousTemplateName: null,
            ucpState: {
                loaded: false,
                isLoading: false,
                form: ucpDefaultForm(),
                savedForm: ucpDefaultForm(),
                meta: {},
                keys: [],
                preview: null,
            },
        };
    },

    watch: {
        'productComparison.templateOptions'(options) {
            if (options?.length) {
                this.detectCurrentTemplate();
            }
        },
        'productExport.provider'() {
            this.detectCurrentTemplate();
            this.syncExportFileName();
        },
    },

    computed: {
        useRouterViewSlot() {
            const result = typeof this.$router?.hasRoute === 'function';
            return result;
        },

        isAgenticCommerce() {
            if (!this.salesChannel) {
                return this.$route.params.typeId === Defaults.agenticCommerceTypeId;
            }

            return this.salesChannel.typeId === Defaults.agenticCommerceTypeId;
        },

        shouldRenderAgenticUi() {
            return this.isAgenticCommerce && !coreShipsAgenticCommerce;
        },

        // The consolidated "Agentic Commerce" tab (UCP card + feature cards) is
        // broader than the product-export surface: UCP config is the plugin's own
        // feature and applies to any exposable channel (Storefront / Headless /
        // Agentic Commerce), but not the native Product Comparison type.
        //
        // It is intentionally NOT gated on `coreShipsAgenticCommerce`: that guard
        // suppresses the plugin's duplicate product-export integration when core
        // ships its own, but UCP configuration is unique to this plugin and must
        // stay reachable regardless. The embedded Product Feed export card keeps
        // its own `isAgenticCommerce` gate.
        shouldRenderAgenticCommerceTab() {
            const typeId = this.salesChannel?.typeId ?? this.$route.params.typeId;

            return Boolean(typeId) && typeId !== Defaults.productComparisonTypeId;
        },

        // Widened to include AC channels so they reuse the product-export blocks.
        // $super() is skipped — it is unreliable during the create flow in 6.5/6.6.
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
                    this.loadUcpState();

                    this.isLoading = false;
                });
        },

        async loadUcpState() {
            if (!this.shouldRenderAgenticCommerceTab || !this.salesChannel?.id) {
                return;
            }

            const salesChannelId = this.salesChannel.id;
            this.ucpState.isLoading = true;

            try {
                const [salesChannelResponse, configResponse, previewResponse, keysResponse] = await Promise.all([
                    this.ucpAdminApiService.getSalesChannel(salesChannelId),
                    this.ucpAdminApiService.getConfig(salesChannelId),
                    this.ucpAdminApiService.getProfilePreview(salesChannelId),
                    this.ucpAdminApiService.getKeys(salesChannelId),
                ]);

                const form = ucpNormalizeConfig(configResponse.data.data || {});

                this.ucpState.meta = salesChannelResponse.data.meta || {};
                this.ucpState.form = form;
                this.ucpState.savedForm = ucpNormalizeConfig(form);
                this.ucpState.preview = previewResponse.data.data || null;
                this.ucpState.keys = keysResponse.data.data || [];
                this.ucpState.loaded = true;
            } catch (error) {
                this.createNotificationError({ message: extractApiErrorMessage(error) });
            } finally {
                this.ucpState.isLoading = false;
            }
        },

        // Persist the UCP config as part of the page's global Save. Returns false
        // to abort the save flow (and the post-save reload) on error so the user
        // can fix and retry. No-op for channels that never loaded UCP state.
        async saveUcpState(salesChannelId) {
            if (!this.ucpState.loaded || !salesChannelId) {
                return true;
            }

            try {
                await this.ucpAdminApiService.saveConfig(salesChannelId, ucpBuildConfigPayload(this.ucpState.form));
                this.ucpState.savedForm = ucpNormalizeConfig(this.ucpState.form);
                return true;
            } catch (error) {
                this.createNotificationError({ message: extractApiErrorMessage(error) });
                return false;
            }
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
            if (!this.validateAgenticCommerceExportConfig()) {
                this.isLoading = false;
                return;
            }

            // 6.7.9.0+ provides saveSalesChannel(); earlier versions inline the save in onSave().
            const isLegacyOnSave = typeof this.saveSalesChannel !== 'function';

            // Snapshot before saving: legacy onSave triggers loadEntityData() which nulls
            // salesChannel and resets agenticCommerceExportConfig mid-flight.
            const channelIdAtSave = this.salesChannel?.id;
            const exportConfigAtSave = this.agenticCommerceExportConfig;
            const providerAtSave = this.productExport?.provider;

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

            const configSaveSuccessful = await this.saveAgenticCommerceExportConfig(
                channelIdAtSave,
                exportConfigAtSave,
                providerAtSave,
            );

            if (!configSaveSuccessful) {
                return;
            }

            const ucpSaveSuccessful = await this.saveUcpState(channelIdAtSave);

            if (!ucpSaveSuccessful) {
                return;
            }

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
                    } catch {
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
            if (this.$route.params.typeId) {
                return;
            }

            if (!this.productComparison.templateOptions?.length || !this.productExport?.provider) {
                return;
            }

            const matchedTemplate = this.productComparison.templateOptions.find((template) => {
                return template.providerName === this.productExport.provider;
            });

            if (matchedTemplate) {
                this.productComparison.templateName = matchedTemplate.name;
            }
        },

        // Align the export filename extension and fileFormat with the active
        // provider's registered template (the plugin's source of truth: jsonl for
        // OpenAI, xml for Google), so the generated feed URL matches the format.
        syncExportFileName() {
            if (!this.productExport?.provider || !this.productExport?.fileName) {
                return;
            }

            const registry = Shopware.Service('exportTemplateService').getProductExportTemplateRegistry();
            const matchedTemplate = Object.values(registry).find((entry) => {
                return entry.providerName === this.productExport.provider;
            });

            if (!matchedTemplate?.fileFormat) {
                return;
            }

            const nextFileName = this.productExport.fileName.replace(/\.[^.]*$/, '') + '.' + matchedTemplate.fileFormat;
            if (nextFileName !== this.productExport.fileName) {
                this.productExport.fileName = nextFileName;
            }

            if (this.productExport.fileFormat !== matchedTemplate.fileFormat) {
                this.productExport.fileFormat = matchedTemplate.fileFormat;
            }
        },

        async saveAgenticCommerceExportConfig(salesChannelIdOverride = null, exportConfigOverride = null, providerOverride = null) {
            const salesChannelId = salesChannelIdOverride ?? this.salesChannel?.id;
            const exportConfig = exportConfigOverride ?? this.agenticCommerceExportConfig;

            // When overrides are provided, skip isAgenticCommerce — salesChannel may
            // have been nulled by the legacy onSave reload path by this point.
            const isAgenticContext = salesChannelIdOverride !== null
                ? Boolean(salesChannelId)
                : this.isAgenticCommerce && Boolean(salesChannelId);

            if (!isAgenticContext) {
                return true;
            }

            const activeProvider = providerOverride
                ?? this.productExport?.provider
                ?? this.defaultAgenticCommerceExportConfig[0]?.provider;

            const loadedConfigs = exportConfig.filter((configEntry) => {
                return configEntry.isLoaded && configEntry.provider === activeProvider;
            });

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
            } catch {
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
