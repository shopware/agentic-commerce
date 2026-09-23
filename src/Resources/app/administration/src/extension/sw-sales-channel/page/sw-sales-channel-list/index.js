import template from './sw-sales-channel-list.html.twig';
import './sw-sales-channel-list.scss';
import { needsOnboarding } from '../../agentic-commerce/onboarding-state';

const { Component } = Shopware;

/**
 * Adds a "UCP" column to the Sales Channels list so merchants can see which
 * channels are exposed to agents at a glance. UCP config lives in the plugin config
 * table (not on the sales_channel entity), so we can't join it into the list query —
 * instead we fetch the per-channel active state via the existing
 * `ucpAdminApiService.getSalesChannels()` endpoint and look it up by id in the
 * `#column-ucpActive` slot.
 *
 * The same response decides whether to show the hint row that points at
 * Settings > Agentic Commerce. It appears only while nothing is exposed, so it
 * disappears once the merchant is set up.
 */
export const swSalesChannelListOverride = {
    template,

    inject: ['acl'],

    data() {
        return {
            ucpActiveMap: {},
            ucpSalesChannels: [],
        };
    },

    computed: {
        canViewUcpStatus() {
            return this.acl?.can?.('ucp.viewer') === true;
        },

        canManageUcpOnboarding() {
            return this.acl?.can?.('ucp.editor') === true;
        },

        // Only while the shop has nothing exposed, so the row disappears once
        // the merchant is set up. needsOnboarding() is false for an empty list,
        // which also keeps it hidden until the channels have loaded.
        showUcpOnboardingHint() {
            return this.canManageUcpOnboarding && needsOnboarding(this.ucpSalesChannels);
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

            return service
                .getSalesChannels()
                .then((response) => {
                    const salesChannels = response?.data?.data ?? [];
                    const map = {};
                    salesChannels.forEach((salesChannel) => {
                        map[salesChannel.id] = Boolean(salesChannel.ucp?.active);
                    });
                    this.ucpActiveMap = map;
                    this.ucpSalesChannels = salesChannels;
                })
                .catch(() => {});
        },
    },
};

Component.override('sw-sales-channel-list', swSalesChannelListOverride);