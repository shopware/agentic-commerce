import template from './sw-agentic-commerce-tracking-config.html.twig';
import { registerOrOverride } from '../../../../helper/register-or-override';

registerOrOverride('sw-agentic-commerce-tracking-config', {
    template,

    emits: ['change'],

    props: {
        salesChannel: {
            type: Object,
            required: true,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    computed: {
        trackingConfig() {
            if (!this.salesChannel.configuration) {
                this.salesChannel.configuration = {};
            }

            return this.salesChannel.configuration;
        },
    },

    methods: {
        onAffiliateCodeChange(value) {
            if (value instanceof Event) return;
            this.trackingConfig.affiliateCode = value ?? '';
            this.$emit('change', { ...this.trackingConfig });
        },

        onCampaignCodeChange(value) {
            if (value instanceof Event) return;
            this.trackingConfig.campaignCode = value ?? '';
            this.$emit('change', { ...this.trackingConfig });
        },
    },
});
