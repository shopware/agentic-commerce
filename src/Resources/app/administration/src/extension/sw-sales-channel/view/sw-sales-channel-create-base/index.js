const { Component } = Shopware;
const utils = Shopware.Utils;

Component.override('sw-sales-channel-create-base', {
    methods: {
        createdComponent() {
            // Inline the parent's createdComponent rather than using $super(),
            // which is unreliable in Shopware 6.5 when the parent component
            // reference is not yet resolved in the override chain.
            this.onGenerateKeys();
            if (this.isProductComparison) {
                this.onGenerateProductExportKey(false);
            }

            if (this.isAgenticCommerce) {
                this.prefillAgenticCommerceDefaults();
            }
        },

        prefillAgenticCommerceDefaults() {
            this.productExport.fileName = `agentic-commerce-${utils.createId()}.jsonl`;
            this.productExport.provider = 'open-ai';
            // Pre-fill provider-neutral defaults. fileFormat is intentionally
            // NOT set here — it is template-specific (jsonl for OpenAI, xml for
            // Google) and must come from the template the user selects.
            // storefrontSalesChannelId, salesChannelDomainId, currencyId and
            // productStreamId still require the user to select values in the UI.
            this.productExport.encoding = 'UTF-8';
            this.productExport.generateByCronjob = false;
            this.productExport.interval = 86400;
        },
    },
});
