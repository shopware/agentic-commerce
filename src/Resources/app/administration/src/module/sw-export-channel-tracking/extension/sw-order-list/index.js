const { Component } = Shopware;

Component.override('sw-order-list', {
    mixins: [Shopware.Mixin.getByName('export-channel-filter')],

    computed: {
        orderCriteria() {
            const criteria = this.$super('orderCriteria');

            criteria.addAssociation('salesChannelTracking.salesChannel');

            return criteria;
        },

        listFilters() {
            const filters = this.$super('listFilters');

            return this.insertExportChannelFilter(
                filters,
                'order',
                'sw-export-channel-tracking.general.exportChannelLabel',
                'sw-export-channel-tracking.general.exportChannelFilterPlaceholder',
            );
        },
    },

    methods: {
        createdComponent() {
            this.defaultFilters.push('export-channel-filter');
            this.loadExportChannelOptions();

            return this.$super('createdComponent');
        },

        getOrderColumns() {
            const columns = this.$super('getOrderColumns');

            columns.push({
                property: 'extensions.salesChannelTracking.salesChannel.name',
                dataIndex: 'extensions.salesChannelTracking.salesChannelId',
                label: 'sw-export-channel-tracking.general.exportChannelLabel',
                allowResize: true,
                visible: false,
            });

            return columns;
        },
    },
});
