const { Component } = Shopware;

export const swSalesChannelCreateOverride = {
    methods: {
        // Validates before $super instead of relying on the sw-sales-channel-detail override: on 6.5/6.6
        // the core create page reaches that override through $super, and its early return leaves core's
        // $super pointer past it, so the next Save would skip the validation and save anyway.
        onSave() {
            if (!this.validateAgenticCommerceExportConfig()) {
                this.isLoading = false;
                return undefined;
            }

            return this.$super('onSave');
        },
    },
};

Component.override('sw-sales-channel-create', swSalesChannelCreateOverride);
