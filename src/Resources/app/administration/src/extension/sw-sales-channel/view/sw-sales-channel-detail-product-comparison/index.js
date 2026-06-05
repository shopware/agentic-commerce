import template from './sw-sales-channel-detail-product-comparison.html.twig';
import './sw-sales-channel-detail-product-comparison.scss';
import { coreShipsAgenticCommerce } from '../../../../core-feature';

const { Component, Defaults } = Shopware;

Component.override('sw-sales-channel-detail-product-comparison', {
    template,

    data() {
        return {
            isLoadingReset: false,
        };
    },

    computed: {
        isAgenticCommerce() {
            return this.salesChannel?.typeId === Defaults.agenticCommerceTypeId;
        },

        // Emit the plugin's own reset button only when core doesn't (avoids
        // duplication). On native cores core emits it; this override's
        // resetToDefault handles it.
        shouldRenderAgenticUi() {
            return this.isAgenticCommerce && !coreShipsAgenticCommerce;
        },
    },

    methods: {
        resetToDefault() {
            const provider = this.productExport.provider || 'open-ai';
            const registry = Shopware.Service('exportTemplateService').getProductExportTemplateRegistry();
            const template = Object.values(registry).find((entry) => entry.providerName === provider);

            if (!template) {
                this.createNotificationError({
                    message: this.$t('sw-sales-channel.detail.agenticCommerce.errorLoadingTemplate'),
                });

                return;
            }

            this.productExport.headerTemplate = template.headerTemplate;
            this.productExport.bodyTemplate = template.bodyTemplate;
            this.productExport.footerTemplate = template.footerTemplate;

            this.createNotificationInfo({
                message: this.$t('sw-sales-channel.detail.agenticCommerce.resetTemplateSuccess'),
            });
        },
    },
});
