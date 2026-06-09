import template from './sw-sales-channel-modal-grid.html.twig';
import './sw-sales-channel-modal-grid.scss';

const { Component, Defaults } = Shopware;

Component.override('sw-sales-channel-modal-grid', {
    template,

    methods: {
        isAgenticCommerceSalesChannelType(salesChannelTypeId) {
            return salesChannelTypeId === Defaults.agenticCommerceTypeId;
        },
    },
});
