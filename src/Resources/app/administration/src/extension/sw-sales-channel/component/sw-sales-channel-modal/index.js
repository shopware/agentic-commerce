const { Component, Defaults } = Shopware;

Component.override('sw-sales-channel-modal', {
    methods: {
        isProductComparisonSalesChannelType(salesChannelTypeId) {
            return (
                salesChannelTypeId === Defaults.productComparisonTypeId ||
                salesChannelTypeId === Defaults.agenticCommerceTypeId
            );
        },
    },
});
