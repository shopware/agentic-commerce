import templateMt from './sw-settings-agentic-commerce-feeds.mt.html.twig';
import templateSw from './sw-settings-agentic-commerce-feeds.sw.html.twig';
import './sw-settings-agentic-commerce-feeds.scss';
import { registerOrOverride } from '../../../../helper/register-or-override';
import { useMtComponents } from '../../../../extension/sw-sales-channel/agentic-commerce/admin-version';
import { extractApiErrorMessage } from '../../../../extension/sw-sales-channel/agentic-commerce/error-message.util';
import { normalizeReadiness, emptyReadiness } from '../../../../extension/sw-sales-channel/agentic-commerce/readiness-state';
import {
    FEED_PROVIDERS,
    STEP_CHOOSE,
    STEP_REVIEW,
    STEP_RESULT,
    feedStepItems,
    feedRows,
    isFeedSelectable,
    defaultFeedSelection,
    isFeedSelected,
    toggleFeed,
    canLeaveFeedStep,
    buildFeedPayload,
    selectedFeedItems,
    feedResultRows,
    copyText,
} from '../../../../extension/sw-sales-channel/agentic-commerce/feed-flow';

const { Mixin } = Shopware;

/**
 * Settings > Agentic Commerce > Connect AI provider.
 *
 * One route with its own step state, like the prepare flow. Applying posts only
 * storefront id and provider pairs: the endpoint copies everything else from
 * the storefront, which is why the merchant is asked nothing beyond the click.
 */
registerOrOverride('sw-settings-agentic-commerce-feeds', {
    template: useMtComponents() ? templateMt : templateSw,

    inject: ['ucpAdminApiService', 'acl'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            readiness: emptyReadiness(),
            step: STEP_CHOOSE,
            selection: [],
            isLoading: true,
            isApplying: false,
            result: null,
        };
    },

    computed: {
        canCreate() {
            return this.acl?.can?.('ucp.editor') === true;
        },

        providers() {
            return FEED_PROVIDERS;
        },

        steps() {
            return feedStepItems(this.step);
        },

        isChooseStep() {
            return this.step === STEP_CHOOSE;
        },

        isReviewStep() {
            return this.step === STEP_REVIEW;
        },

        hasResult() {
            return this.step === STEP_RESULT && this.result !== null;
        },

        rows() {
            return feedRows(this.readiness);
        },

        selectableCount() {
            return defaultFeedSelection(this.rows).length;
        },

        selectedCount() {
            return this.selection.length;
        },

        selectedItems() {
            return selectedFeedItems(this.rows, this.selection);
        },

        canContinue() {
            return canLeaveFeedStep(this.step, this.selection);
        },

        applyDisabled() {
            return !this.canCreate || this.isApplying || this.selection.length === 0;
        },

        resultRows() {
            return feedResultRows(this.result, this.rows);
        },

        resultCounts() {
            return {
                created: this.result?.created ?? 0,
                skipped: this.result?.skipped ?? 0,
                failed: this.result?.failed ?? 0,
            };
        },

    },

    created() {
        this.loadReadiness();
    },

    methods: {
        loadReadiness() {
            this.isLoading = true;

            return this.ucpAdminApiService
                .getReadiness()
                .then((response) => {
                    this.readiness = normalizeReadiness(response?.data?.data);
                    this.selection = defaultFeedSelection(this.rows);
                })
                .catch((error) => {
                    this.createNotificationError({ message: extractApiErrorMessage(error) });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        feedOf(row, provider) {
            return row.feeds[provider.key] ?? null;
        },

        isSelectable(row, provider) {
            return this.canCreate && isFeedSelectable(row, provider.key);
        },

        isSelected(row, provider) {
            return isFeedSelected(this.selection, row.id, provider.key);
        },

        tileClasses(row, provider) {
            return {
                'sw-agentic-feeds__tile--connected': this.feedOf(row, provider) !== null,
                'sw-agentic-feeds__tile--disabled': this.feedOf(row, provider) === null && !this.isSelectable(row, provider),
                'sw-agentic-feeds__tile--selected': this.isSelected(row, provider),
            };
        },

        onToggleTile(row, provider) {
            if (!this.isSelectable(row, provider)) {
                return;
            }

            this.selection = toggleFeed(this.selection, row.id, provider.key, !this.isSelected(row, provider));
        },

        selectAll() {
            this.selection = defaultFeedSelection(this.rows);
        },

        deselectAll() {
            this.selection = [];
        },

        channelRoute(salesChannelId) {
            return { name: 'sw.sales.channel.detail', params: { id: salesChannelId } };
        },

        copyFeedUrl(url) {
            return copyText(url)
                .then(() => {
                    this.createNotificationSuccess({ message: this.$t('swagAgenticCommerce.feeds.copied') });
                })
                .catch(() => {
                    this.createNotificationError({ message: this.$t('swagAgenticCommerce.feeds.copyFailed') });
                });
        },

        onNext() {
            if (!this.canContinue) {
                return;
            }

            this.step = STEP_REVIEW;
        },

        onBack() {
            if (this.isChooseStep) {
                this.onClose();

                return;
            }

            this.step = STEP_CHOOSE;
        },

        onClose() {
            this.$router.push({ name: 'sw.settings.agentic.commerce.index' });
        },

        apply() {
            if (this.applyDisabled) {
                return;
            }

            this.isApplying = true;

            return this.ucpAdminApiService
                .createFeedChannels(buildFeedPayload(this.selection))
                .then((response) => {
                    this.result = response?.data?.data ?? null;

                    if (this.result !== null) {
                        this.step = STEP_RESULT;
                    }
                })
                .catch((error) => {
                    this.createNotificationError({ message: extractApiErrorMessage(error) });
                })
                .finally(() => {
                    this.isApplying = false;
                });
        },
    },
});
