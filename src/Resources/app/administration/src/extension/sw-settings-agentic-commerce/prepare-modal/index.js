import template from './sw-settings-agentic-commerce-prepare-modal.html.twig';
import './sw-settings-agentic-commerce-prepare-modal.scss';

const { Component, Mixin } = Shopware;
const { Criteria, EntityCollection } = Shopware.Data;

/**
 * Modal for the "prepare sales channels" flow. Lists the transactional
 * (Storefront/Headless) sales channels that do not have UCP enabled yet in a
 * multi-select, pre-selects all of them, and — on confirm — activates UCP plus
 * the AI files for the selected channels via
 * `ucpAdminApiService.prepareSalesChannels()`.
 *
 * Which channels are still unprepared is known only to the plugin (UCP config
 * lives in its own table), so that set comes from `getSalesChannels()`. The
 * multi-select itself is bound to the `sales_channel` repository so it renders
 * the translated channel names, and is constrained to the unprepared ids.
 */
export const swSettingsAgenticCommercePrepareModal = {
    template,

    inject: ['repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    emits: ['prepared', 'close'],

    data() {
        return {
            unpreparedIds: [],
            selectedSalesChannelsCollection: null,
            isLoading: false,
            isPreparing: false,
        };
    },

    computed: {
        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        salesChannelCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equalsAny('id', this.unpreparedIds));

            return criteria;
        },

        hasUnpreparedSalesChannels() {
            return this.unpreparedIds.length > 0;
        },

        selectedIds() {
            return this.selectedSalesChannelsCollection ? this.selectedSalesChannelsCollection.getIds() : [];
        },

        hasSelection() {
            return this.selectedIds.length > 0;
        },
    },

    created() {
        this.loadUnpreparedSalesChannels();
    },

    methods: {
        loadUnpreparedSalesChannels() {
            const service = Shopware.Service('ucpAdminApiService');
            if (!service?.getSalesChannels) {
                return;
            }

            this.isLoading = true;

            service
                .getSalesChannels()
                .then((response) => {
                    // The endpoint already returns only transactional channels; keep the ones
                    // without UCP active, i.e. the ones still to prepare.
                    this.unpreparedIds = (response?.data?.data ?? [])
                        .filter((salesChannel) => !salesChannel.ucp?.active)
                        .map((salesChannel) => salesChannel.id);

                    return this.buildSelectedSalesChannelsCollection();
                })
                .then(() => {
                    this.isLoading = false;
                })
                .catch(() => {
                    this.isLoading = false;
                    this.createNotificationError({
                        message: this.$t('swagAgenticCommerce.prepareModal.loadErrorMessage'),
                    });
                });
        },

        buildSelectedSalesChannelsCollection() {
            if (this.unpreparedIds.length === 0) {
                this.selectedSalesChannelsCollection = new EntityCollection(
                    '/sales-channel',
                    'sales_channel',
                    Shopware.Context.api,
                    new Criteria(1, 25),
                );

                return Promise.resolve();
            }

            // Pre-select all unprepared channels (per the ticket).
            return this.salesChannelRepository.search(this.salesChannelCriteria, Shopware.Context.api).then((result) => {
                this.selectedSalesChannelsCollection = result;
            });
        },

        onCancel() {
            this.$emit('close');
        },

        onConfirm() {
            if (!this.hasSelection || this.isPreparing) {
                return;
            }

            const service = Shopware.Service('ucpAdminApiService');
            this.isPreparing = true;

            service
                .prepareSalesChannels(this.selectedIds)
                .then((response) => {
                    this.isPreparing = false;
                    this.createNotificationSuccess({
                        message: this.$t('swagAgenticCommerce.prepareModal.successMessage'),
                    });
                    this.$emit('prepared', response?.data?.data ?? null);
                })
                .catch(() => {
                    this.isPreparing = false;
                    this.createNotificationError({
                        message: this.$t('swagAgenticCommerce.prepareModal.errorMessage'),
                    });
                });
        },
    },
};

Component.register('sw-settings-agentic-commerce-prepare-modal', swSettingsAgenticCommercePrepareModal);
