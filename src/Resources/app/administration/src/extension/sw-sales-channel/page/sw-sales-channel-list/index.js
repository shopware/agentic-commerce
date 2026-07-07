import template from './sw-sales-channel-list.html.twig';

const { Component } = Shopware;

/**
 * Adds a "UCP" column to the Sales Channels list so merchants can see which
 * channels are exposed to agents at a glance. UCP config lives in the plugin config
 * table (not on the sales_channel entity), so we can't join it into the list query —
 * instead we fetch the per-channel active state via the existing
 * `ucpAdminApiService.getSalesChannels()` endpoint and look it up by id in the
 * `#column-ucpActive` slot.
 */
export const swSalesChannelListOverride = {
    template,

    inject: ['acl'],

    data() {
        return {
            ucpActiveMap: {},
        };
    },

    computed: {
        canViewUcpStatus() {
            return this.acl?.can?.('ucp.viewer') === true;
        },

        salesChannelColumns() {
            const columns = this.$super('salesChannelColumns');
            if (!this.canViewUcpStatus || columns.some((column) => column.property === 'ucpActive')) {
                return columns;
            }

            const ucpColumn = {
                property: 'ucpActive',
                label: 'swagAgenticCommerce.salesChannelList.columnUcp',
                allowResize: false,
                sortable: false,
                align: 'center',
            };

            const statusIndex = columns.findIndex((column) => column.property === 'status');
            if (statusIndex >= 0) {
                columns.splice(statusIndex + 1, 0, ucpColumn);
            } else {
                columns.push(ucpColumn);
            }

            return columns;
        },
    },

    created() {
        if (!this.canViewUcpStatus) {
            return;
        }

        this.loadUcpActiveStates();
    },

    methods: {
        loadUcpActiveStates() {
            if (!this.canViewUcpStatus) {
                return;
            }

            const service = Shopware.Service('ucpAdminApiService');
            if (!service?.getSalesChannels) {
                return;
            }

            service
                .getSalesChannels()
                .then((response) => {
                    const map = {};
                    (response?.data?.data ?? []).forEach((salesChannel) => {
                        map[salesChannel.id] = Boolean(salesChannel.ucp?.active);
                    });
                    this.ucpActiveMap = map;
                })
                .catch(() => {});
        },
    },
};

Component.override('sw-sales-channel-list', swSalesChannelListOverride);
