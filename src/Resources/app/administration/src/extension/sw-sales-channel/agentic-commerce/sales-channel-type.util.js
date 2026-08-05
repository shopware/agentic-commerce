const { Defaults } = Shopware;

export function isTransactionalSalesChannelType(typeId) {
    return [
        Defaults.storefrontSalesChannelTypeId,
        Defaults.apiSalesChannelTypeId,
    ].includes(typeId);
}
