const { Component } = Shopware;

Component.override('sw-customer-list', {
    mixins: [Shopware.Mixin.getByName('export-channel-filter')],

    computed: {
        defaultCriteria() {
            const criteria = this.$super('defaultCriteria');

            criteria.addAssociation('salesChannelTracking.salesChannel');

            return criteria;
        },

        listFilters() {
            const filters = this.$super('listFilters');

            return this.insertExportChannelFilter(
                filters,
                'customer',
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

        getCustomerColumns() {
            const columns = this.$super('getCustomerColumns');

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
