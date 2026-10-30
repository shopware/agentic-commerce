import template from './sw-sales-channel-detail.html.twig';
import './sw-sales-channel-detail.scss';
import { coreShipsAgenticCommerce } from '../../../../core-feature';
import { mergeAgenticFileDrafts, saveAgenticFileConfigurations } from '../../agentic-file-persistence';
import {
    defaultForm as ucpDefaultForm,
    normalizeConfig as ucpNormalizeConfig,
    buildConfigPayload as ucpBuildConfigPayload,
} from '../../agentic-commerce/ucp-form-state';
import { extractApiErrorMessage } from '../../agentic-commerce/error-message.util';
import { isKnownSalesChannelType, isTransactionalSalesChannelType } from '../../agentic-commerce/sales-channel-type.util';

const { Component, Context, Defaults } = Shopware;
const objectHelper = Shopware.Utils.object;
const ShopwareError = Shopware.Classes.ShopwareError;

/**
 * Exported so unit tests can exercise the override methods directly with a
 * mock `this`; the override registration below is the production wiring.
 */
export const swSalesChannelDetailOverride = {
    template,

    inject: ['systemConfigApiService', 'ucpAdminApiService', 'acl', 'repositoryFactory'],

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
            isSavingUcp: false,
            ucpState: {
                loaded: false,
                isLoading: false,
                form: ucpDefaultForm(),
                savedForm: ucpDefaultForm(),
                meta: {},
                preview: null,
                transactional: false,
                // Bumped by every UCP write; a load started before the write must not apply.
                generation: 0,
            },
            pendingAgenticFilesReconciliation: null,
            pendingAgenticFilesReload: null,
        };
    },

    watch: {
        salesChannel(salesChannel) {
            const pendingReconciliation = this.pendingAgenticFilesReconciliation;
            if (!pendingReconciliation) {
                return;
            }

            if (this.$route.params.id?.toLowerCase() !== pendingReconciliation.salesChannelId) {
                this.pendingAgenticFilesReconciliation = null;
                return;
            }

            if (salesChannel?.id !== pendingReconciliation.salesChannelId) {
                return;
            }

            salesChannel.salesChannelFiles = pendingReconciliation.salesChannelFiles;
            this.pendingAgenticFilesReconciliation = null;
        },
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
        isAgenticCommerce() {
            if (!this.salesChannel) {
                return this.$route.params.typeId === Defaults.agenticCommerceTypeId;
            }

            return this.salesChannel.typeId === Defaults.agenticCommerceTypeId;
        },

        shouldRenderAgenticUi() {
            return this.isAgenticCommerce && !coreShipsAgenticCommerce;
        },

        agenticCommerceStatisticsRoute() {
            return coreShipsAgenticCommerce
                ? 'sw.sales.channel.detail.productExportInsights'
                : 'sw.sales.channel.detail.agenticCommerceStatistics';
        },

        shouldRenderAgenticCommerceTab() {
            const typeId = this.salesChannel?.typeId ?? this.$route.params.typeId;

            return this.acl.can('ucp.viewer')
                && (isTransactionalSalesChannelType(typeId) || this.ucpState.transactional);
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
        createdComponent() {
            this.$super('createdComponent');

            if (this.isAgenticCommerce && this.productExport?.isNew()) {
                this.onTemplateSelected('open_ai');
            }
        },

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

        async loadUcpState(salesChannelId = this.salesChannel?.id, typeId = this.salesChannel?.typeId) {
            if (!salesChannelId || !this.acl.can('ucp.viewer')) {
                return;
            }

            const resolveRemotely = !this.shouldRenderAgenticCommerceTab;
            if (resolveRemotely && isKnownSalesChannelType(typeId)) {
                return;
            }

            const generation = this.ucpState.generation;
            this.ucpState.isLoading = true;

            // The three responses answer independent questions, so each is applied from its
            // own handler the moment it arrives. Two reasons not to gate them on each other:
            //
            // - `meta` must never be lost. It reports what the *platform* supports (whether
            //   this Shopware exposes a Store-API MCP server) and decides which transports are
            //   offered at all. Discarding it because the preview call failed renders the form
            //   as if the platform had no MCP — indistinguishable from a shop that has it
            //   switched off, so the merchant sees a plausible wrong answer instead of an error.
            // - A slow request must not hold back a fast one. Waiting for all three (either
            //   Promise.all or allSettled) means one hanging request delays the others' data,
            //   the error notification and the spinner reset.
            let reported = false;
            const report = (error) => {
                if (reported) {
                    return;
                }

                reported = true;
                // Stop the spinner with the first failure rather than leaving it turning until
                // a request that may never settle finally does.
                this.ucpState.isLoading = false;
                this.createNotificationError({ message: extractApiErrorMessage(error) });
            };

            try {
                const salesChannelView = this.ucpAdminApiService.getSalesChannel(salesChannelId).then((response) => {
                    this.ucpState.meta = response.data.meta || {};
                    this.ucpState.transactional = Boolean(response.data.data?.transactional);
                }, report);

                // A type this plugin does not know is classified by the backend's resolver; config and
                // preview are only worth fetching once it says the channel can sell.
                if (resolveRemotely) {
                    await salesChannelView;
                    if (!this.ucpState.transactional) {
                        return;
                    }
                }

                await Promise.all([
                    salesChannelView,

                    this.ucpAdminApiService.getConfig(salesChannelId).then((response) => {
                        if (generation !== this.ucpState.generation) {
                            return;
                        }

                        const form = ucpNormalizeConfig(response.data.data || {});

                        this.ucpState.form = form;
                        this.ucpState.savedForm = ucpNormalizeConfig(form);
                        // Only with the saved config is there something trustworthy to edit.
                        this.ucpState.loaded = true;
                    }, report),

                    this.ucpAdminApiService.getProfilePreview(salesChannelId).then((response) => {
                        if (generation !== this.ucpState.generation) {
                            return;
                        }

                        this.ucpState.preview = response.data.data || null;
                    }, report),
                ]);
            } catch (error) {
                // Every rejection is already absorbed by `report`, so this only catches a
                // throw from one of the fulfilled handlers.
                report(error);
            } finally {
                this.ucpState.isLoading = false;
            }
        },

        // Persist the UCP config as part of the page's global Save. Returns false
        // to abort the save flow (and the post-save reload) on error so the user
        // can fix and retry. No-op for channels that never loaded UCP state.
        //
        // `form` is the form as it was when Save was clicked: the legacy onSave reloads
        // the page before this write, which replaces `ucpState.form` with the stored,
        // not yet updated config.
        async saveUcpState(salesChannelId, form) {
            if (!form || !salesChannelId) {
                return true;
            }

            const isActivatingUcp = form.active && !this.ucpState.savedForm?.active;

            try {
                await this.ucpAdminApiService.saveConfig(salesChannelId, ucpBuildConfigPayload(form));
                this.ucpState.generation += 1;
                this.ucpState.form = ucpNormalizeConfig(form);
                this.ucpState.savedForm = ucpNormalizeConfig(form);
            } catch (error) {
                // Keep the draft so the user can fix and retry: a reload already in flight
                // from the legacy onSave would otherwise replace it with the stored config.
                this.ucpState.generation += 1;
                this.ucpState.form = ucpNormalizeConfig(form);
                this.createNotificationError({ message: extractApiErrorMessage(error) });
                return false;
            }

            if (isActivatingUcp) {
                try {
                    await this.reloadAgenticFiles(salesChannelId);
                } catch {
                    this.pendingAgenticFilesReload = salesChannelId;
                }
            }

            return true;
        },

        async reloadAgenticFiles(salesChannelId) {
            const hasCoreAgenticFiles = Boolean(
                Shopware?.Component?.getComponentRegistry?.().has('sw-sales-channel-detail-agentic-files'),
            );
            if (!hasCoreAgenticFiles) {
                return;
            }

            const salesChannel = await this.salesChannelRepository.get(
                salesChannelId,
                Context.api,
                this.getLoadSalesChannelCriteria(),
            );

            if (this.salesChannel?.id === salesChannelId) {
                if (this.salesChannel.salesChannelFiles?.length) {
                    mergeAgenticFileDrafts(
                        this.salesChannel.salesChannelFiles,
                        salesChannel.salesChannelFiles,
                        this.repositoryFactory.create('sales_channel_file'),
                    );
                }

                this.salesChannel.salesChannelFiles = salesChannel.salesChannelFiles;
                this.pendingAgenticFilesReload = null;
                return;
            }

            if (this.$route.params.id?.toLowerCase() === salesChannelId) {
                this.pendingAgenticFilesReconciliation = {
                    salesChannelId,
                    salesChannelFiles: salesChannel.salesChannelFiles,
                };
            }
        },

        onTemplateSelected(templateName) {
            if (this.productComparison.templates === null || this.productComparison.templates[templateName] === undefined) {
                return;
            }

            this.productComparison.selectedTemplate = { ...this.productComparison.templates[templateName] };

            if (this.productExport.isNew()) {
                this.productComparison.templateName = templateName;
                this.onTemplateModalConfirm();
                return;
            }

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

            if (this.productExport.isNew()) {
                return;
            }

            this.createNotificationInfo({
                message: this.$t('sw-sales-channel.detail.productComparison.templates.message.template-applied-message'),
            });
        },

        async onSave() {
            if (!this.allowSaving || this.isLoading || this.isSavingUcp) {
                return;
            }

            if (this.pendingAgenticFilesReload
                && this.pendingAgenticFilesReload === this.salesChannel?.id) {
                this.isLoading = true;
                this.isSaveSuccessful = false;
                try {
                    await this.reloadAgenticFiles(this.salesChannel.id);
                } catch {
                    this.createNotificationError({ message: this.$t('sw-sales-channel.detail.messageAPIError') });
                    return;
                } finally {
                    this.isLoading = false;
                }
            }

            if (!this.isAgenticCommerce) {
                const salesChannelId = this.salesChannel?.id;
                const form = this.ucpState.loaded ? ucpNormalizeConfig(this.ucpState.form) : null;
                const hasUcpChanges = form
                    && JSON.stringify(form) !== JSON.stringify(ucpNormalizeConfig(this.ucpState.savedForm));

                // Keep core validation and Storefront theme assignment in the save chain.
                // The parent owns the reload; unchanged UCP settings must not delay it.
                this.isSaveSuccessful = false;
                await this.$super('onSave');

                if (this.isSaveSuccessful && hasUcpChanges) {
                    this.isSavingUcp = true;
                    this.isLoading = true;
                    this.isSaveSuccessful = false;
                    try {
                        this.isSaveSuccessful = await this.saveUcpState(salesChannelId, form);

                        if (this.isSaveSuccessful) {
                            this.refreshUcpPreview(salesChannelId);
                        }
                    } finally {
                        this.isSavingUcp = false;
                        this.isLoading = false;
                    }
                }

                return;
            }

            if (!this.validateAgenticCommerceExportConfig()) {
                this.isLoading = false;
                return;
            }

            // 6.7.9.0+ provides saveSalesChannel(); earlier versions inline the save in onSave().
            const isLegacyOnSave = typeof this.saveSalesChannel !== 'function';

            // Snapshot before saving: legacy onSave triggers loadEntityData() which nulls
            // salesChannel and resets agenticCommerceExportConfig mid-flight.
            const channelIdAtSave = this.salesChannel?.id;
            const typeIdAtSave = this.salesChannel?.typeId;
            const exportConfigAtSave = this.agenticCommerceExportConfig;
            const providerAtSave = this.productExport?.provider;
            const form = this.ucpState.loaded ? ucpNormalizeConfig(this.ucpState.form) : null;
            const ucpFormAtSave = form
                && JSON.stringify(form) !== JSON.stringify(ucpNormalizeConfig(this.ucpState.savedForm)) ? form : null;

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

            const ucpSaveSuccessful = await this.saveUcpState(channelIdAtSave, ucpFormAtSave);

            if (!ucpSaveSuccessful) {
                this.isSaveSuccessful = false;
                return;
            }

            if (!isLegacyOnSave) {
                this.loadEntityData();
                return;
            }

            // The legacy onSave reloaded the page before the UCP write; load what is stored now.
            if (ucpFormAtSave) {
                this.loadUcpState(channelIdAtSave, typeIdAtSave);
            }
        },

        async saveAgenticFiles() {
            const configurations = this.salesChannel?.salesChannelFiles;
            if (!configurations?.length) {
                return true;
            }

            try {
                const allSaved = await saveAgenticFileConfigurations(
                    configurations,
                    this.repositoryFactory.create('sales_channel_file'),
                    Context.api,
                );
                if (allSaved) {
                    return true;
                }
            } catch {
                // Repository failures and incomplete saves share the same notification.
            }

            this.createNotificationError({
                message: this.$t('sw-sales-channel.detail.messageAPIError'),
            });
            return false;
        },

        /** @private */
        refreshUcpPreview(salesChannelId) {
            return this.ucpAdminApiService.getProfilePreview(salesChannelId).then((response) => {
                this.ucpState.preview = response.data.data || null;
            }).catch(() => {});
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
