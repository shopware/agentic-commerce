import template from './sw-settings-ucp-index.html.twig';
import './sw-settings-ucp-index.scss';
import { extractApiErrorMessage } from '../../error-message.util.js';
import { formatShopwareVersion } from '../../shopware-version.util.js';

const { Mixin } = Shopware;

export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['ucpAdminApiService', 'acl'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            activatingSalesChannelId: null,
            salesChannels: [],
            meta: {},
        };
    },

    created() {
        this.loadSalesChannels();
    },

    computed: {
        formattedShopwareVersion() {
            return formatShopwareVersion(this.meta.shopwareVersion);
        },

        activeSalesChannels() {
            return this.salesChannels
                .filter((salesChannel) => salesChannel.ucp && salesChannel.ucp.active)
                .sort((left, right) => left.name.localeCompare(right.name));
        },

        inactiveSalesChannels() {
            return this.salesChannels
                .filter((salesChannel) => !salesChannel.ucp || !salesChannel.ucp.active)
                .sort((left, right) => left.name.localeCompare(right.name));
        },

        hasActiveSalesChannels() {
            return this.activeSalesChannels.length > 0;
        },

        hasInactiveSalesChannels() {
            return this.inactiveSalesChannels.length > 0;
        },

        canEditConfig() {
            return this.acl.can('ucp.editor');
        },
    },

    methods: {
        async loadSalesChannels() {
            this.isLoading = true;

            try {
                const response = await this.ucpAdminApiService.getSalesChannels();
                this.salesChannels = response.data.data || [];
                this.meta = response.data.meta || {};
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: extractApiErrorMessage(error),
                });
            } finally {
                this.isLoading = false;
            }
        },

        ucpDetailRoute(salesChannel) {
            return { name: 'sw.settings.ucp.detail', params: { salesChannelId: salesChannel.id } };
        },

        salesChannelRoute(salesChannel) {
            return { name: 'sw.sales.channel.detail.base', params: { id: salesChannel.id } };
        },

        capabilityCount(salesChannel) {
            return salesChannel.ucp && Array.isArray(salesChannel.ucp.enabledCapabilities) ? salesChannel.ucp.enabledCapabilities.length : 0;
        },

        transportSummary(salesChannel) {
            const transports = salesChannel.ucp && Array.isArray(salesChannel.ucp.enabledTransports) ? salesChannel.ucp.enabledTransports : [];

            if (transports.length === 0) {
                return this.$tc('sw-settings-ucp.general.noTransportLabel');
            }

            return transports.map((entry) => entry.toUpperCase()).join(', ');
        },

        statusLabel(salesChannel) {
            return salesChannel.ucp && salesChannel.ucp.active
                ? this.$tc('sw-settings-ucp.general.statusActive')
                : this.$tc('sw-settings-ucp.general.statusInactive');
        },

        statusClass(salesChannel) {
            return salesChannel.ucp && salesChannel.ucp.active ? 'is--active' : 'is--inactive';
        },

        async activateSalesChannel(salesChannel) {
            if (!this.canEditConfig) {
                return;
            }

            this.activatingSalesChannelId = salesChannel.id;

            try {
                const response = await this.ucpAdminApiService.getConfig(salesChannel.id);
                const config = response.data.data || {};

                await this.ucpAdminApiService.saveConfig(salesChannel.id, {
                    ...config,
                    active: true,
                });

                await this.loadSalesChannels();

                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc('sw-settings-ucp.general.salesChannelActivated'),
                });
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: extractApiErrorMessage(error),
                });
            } finally {
                this.activatingSalesChannelId = null;
            }
        },

        isActivatingSalesChannel(salesChannel) {
            return this.activatingSalesChannelId === salesChannel.id;
        },
    },
};
