import { registerOrOverride } from '../../../../helper/register-or-override';

const { Defaults } = Shopware;

/**
 * The core Insights page also serves ordinary product-comparison channels.
 * Restrict only Agentic Commerce statistics and keep core behavior elsewhere.
 */
export const productExportInsightsOverride = {
    inject: ['acl'],

    computed: {
        isAgenticCommerceChannel() {
            return this.salesChannel?.typeId === Defaults.agenticCommerceTypeId;
        },
    },

    methods: {
        async fetchData() {
            if (this.isAgenticCommerceChannel && !this.acl.can('ucp.viewer')) {
                this.isLoading = false;
                return;
            }

            return this.$super('fetchData');
        },
    },
};

registerOrOverride('sw-sales-channel-detail-product-export-insights', productExportInsightsOverride);
