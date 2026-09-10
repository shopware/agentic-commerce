const { Defaults } = Shopware;

export function isTransactionalSalesChannelType(typeId) {
    return [
        Defaults.storefrontSalesChannelTypeId,
        Defaults.apiSalesChannelTypeId,
    ].includes(typeId);
}

// Any other type is classified by the backend's sales-channel type resolver.
export function isKnownSalesChannelType(typeId) {
    return [
        Defaults.storefrontSalesChannelTypeId,
        Defaults.apiSalesChannelTypeId,
        Defaults.productComparisonTypeId,
        Defaults.agenticCommerceTypeId,
    ].includes(typeId);
}
