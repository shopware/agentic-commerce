import templateMt from './sw-settings-agentic-commerce-index.mt.html.twig';
import templateSw from './sw-settings-agentic-commerce-index.sw.html.twig';
import './sw-settings-agentic-commerce-index.scss';
import { registerOrOverride } from '../../../../helper/register-or-override';
import { useMtComponents } from '../../../../extension/sw-sales-channel/agentic-commerce/admin-version';
import { extractApiErrorMessage } from '../../../../extension/sw-sales-channel/agentic-commerce/error-message.util';
import {
    normalizeReadiness,
    emptyReadiness,
    statusVariant,
    statusSnippet,
    progressPercent,
    readinessTasks,
    preparedChannels,
    actionableChannelFindings,
    hasChannelsToPrepare,
    taskDescriptionKey,
    taskActionKey,
} from '../../../../extension/sw-sales-channel/agentic-commerce/readiness-state';
import {
    connectedFeeds,
    hasMissingFeeds,
    copyText,
} from '../../../../extension/sw-sales-channel/agentic-commerce/feed-flow';

const { Mixin } = Shopware;

/**
 * Settings > Agentic Commerce.
 *
 * A thin renderer over the readiness endpoint: one read gives the status, the
 * three setup steps and every offerable channel with its outstanding findings.
 * All the rules about what that means live in readiness-state.js.
 *
 * The prepare and connect actions route to their own flows; this page re-reads
 * the endpoint when it is entered again, so it reflects what was applied.
 */
registerOrOverride('sw-settings-agentic-commerce-index', {
    template: useMtComponents() ? templateMt : templateSw,

    inject: ['ucpAdminApiService', 'acl'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            readiness: emptyReadiness(),
            isLoading: true,
        };
    },

    computed: {
        canPrepare() {
            return this.acl?.can?.('ucp.editor') === true;
        },

        statusVariant() {
            return statusVariant(this.readiness);
        },

        statusSnippets() {
            return statusSnippet(this.readiness);
        },

        progressPercent() {
            return progressPercent(this.readiness);
        },

        tasks() {
            return readinessTasks(this.readiness);
        },

        preparedChannels() {
            return preparedChannels(this.readiness);
        },

        findings() {
            return actionableChannelFindings(this.readiness);
        },

        hasChannelsToPrepare() {
            return hasChannelsToPrepare(this.readiness);
        },

        preparedCount() {
            return this.readiness.steps?.prepared?.count ?? 0;
        },

        connectedFeeds() {
            return connectedFeeds(this.readiness);
        },

        hasMissingFeeds() {
            return hasMissingFeeds(this.readiness);
        },

        agenticChannelCount() {
            return this.readiness.steps?.connected?.count ?? 0;
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
                })
                .catch((error) => {
                    this.createNotificationError({ message: extractApiErrorMessage(error) });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        taskLabel(task, suffix) {
            return `swagAgenticCommerce.settings.tasks.${task.key}.${suffix}`;
        },

        taskDescription(task) {
            return taskDescriptionKey(task);
        },

        taskAction(task) {
            return taskActionKey(task);
        },

        openPrepare() {
            this.$router.push({ name: 'sw.settings.agentic.commerce.prepare' });
        },

        openFeeds() {
            this.$router.push({ name: 'sw.settings.agentic.commerce.feeds' });
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
    },
});