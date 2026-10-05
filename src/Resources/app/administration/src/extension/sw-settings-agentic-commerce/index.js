import template from './sw-settings-agentic-commerce.html.twig';
import './prepare-modal';

const { Component } = Shopware;

/**
 * Drives the counts on the core Agentic Commerce settings page and owns the
 * "prepare sales channels" flow once the plugin is active. The counts are
 * computed server-side (UCP config lives in the plugin config table, not on the
 * sales_channel entity) and fetched via `ucpAdminApiService.getReadiness()`.
 *
 * The core settings page only exists on Shopware versions that ship it, so the
 * override is registered conditionally — on older versions there is nothing to
 * override and the plugin keeps its sales-channel-scoped admin instead.
 */
export const swSettingsAgenticCommerceOverride = {
    template,

    inject: ['acl'],

    data() {
        return {
            showPrepareModal: false,
            transactionalSalesChannelCount: 0,
        };
    },

    computed: {
        canViewUcpStatus() {
            return this.acl?.can?.('ucp.viewer') === true;
        },

        // Marks the "prepare" step complete once every transactional (Storefront/Headless)
        // sales channel has UCP enabled. Comparing against the total keeps the flag false
        // before the counts load (all zero) and before any channel exists.
        allSalesChannelsPrepared() {
            return (
                this.transactionalSalesChannelCount > 0 &&
                this.preparedSalesChannelCount >= this.transactionalSalesChannelCount
            );
        },

        // The plugin's admin bundle only loads when the plugin is installed and active,
        // so here the extension step is always complete; the prepare step reflects the
        // actual preparation state.
        steps() {
            return this.$super('steps').map((step) => {
                if (step.key === 'extension') {
                    return {
                        ...step,
                        complete: true,
                        description: this.$t('swagAgenticCommerce.extensionStep.activeDescription'),
                    };
                }

                if (step.key === 'prepareSalesChannels') {
                    const complete = this.allSalesChannelsPrepared;

                    return {
                        ...step,
                        complete,
                        description: complete
                            ? this.$t('swagAgenticCommerce.prepareStep.completedDescription')
                            : step.description,
                    };
                }

                return step;
            });
        },

        canPrepareSalesChannels() {
            return (
                this.acl?.can?.('ucp.editor') === true &&
                this.preparedSalesChannelCount < this.transactionalSalesChannelCount
            );
        },

        readinessSubtitle() {
            return this.isReady
                ? this.$t('swagAgenticCommerce.subtitleReady')
                : this.$t('swagAgenticCommerce.subtitleActive');
        },

        extensionStatusLabel() {
            return this.$t('swagAgenticCommerce.shopReadiness.extensionInstalled');
        },
    },

    created() {
        this.loadReadinessCounts();
    },

    methods: {
        loadReadinessCounts() {
            if (!this.canViewUcpStatus) {
                return;
            }

            const service = Shopware.Service('ucpAdminApiService');
            if (!service?.getReadiness) {
                return;
            }

            service
                .getReadiness()
                .then((response) => {
                    this.applyReadiness(response?.data?.data ?? {});
                })
                .catch(() => {});
        },

        applyReadiness(data) {
            this.preparedSalesChannelCount = data.preparedSalesChannels ?? 0;
            this.agenticSalesChannelCount = data.agenticSalesChannels ?? 0;
            this.transactionalSalesChannelCount = data.transactionalSalesChannels ?? 0;
        },

        // Overrides the core no-op: open the selection modal instead.
        onPrepareSalesChannels() {
            this.showPrepareModal = true;
        },

        onPrepareModalClose() {
            this.showPrepareModal = false;
        },

        onSalesChannelsPrepared(summary) {
            this.showPrepareModal = false;

            if (summary) {
                this.applyReadiness(summary);
                return;
            }

            this.loadReadinessCounts();
        },
    },
};

if (Component.getComponentRegistry().has('sw-settings-agentic-commerce')) {
    Component.override('sw-settings-agentic-commerce', swSettingsAgenticCommerceOverride);
}
