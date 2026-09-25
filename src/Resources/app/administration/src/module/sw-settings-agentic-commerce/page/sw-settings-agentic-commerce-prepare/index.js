import templateMt from './sw-settings-agentic-commerce-prepare.mt.html.twig';
import templateSw from './sw-settings-agentic-commerce-prepare.sw.html.twig';
import './sw-settings-agentic-commerce-prepare.scss';
import { registerOrOverride } from '../../../../helper/register-or-override';
import { useMtComponents } from '../../../../extension/sw-sales-channel/agentic-commerce/admin-version';
import { extractApiErrorMessage } from '../../../../extension/sw-sales-channel/agentic-commerce/error-message.util';
import {
    normalizeReadiness,
    emptyReadiness,
    prepareRows,
    defaultPrepareSelection,
} from '../../../../extension/sw-sales-channel/agentic-commerce/readiness-state';
import {
    STEP_UNDERSTAND,
    STEP_SELECT,
    STEP_REVIEW,
    stepItems,
    nextStep,
    previousStep,
    isFirstStep,
    buildPreparePayload,
    canLeaveStep,
    toggleSelection,
    selectedRows,
    outcomesById,
    actionableOutcomeFindings,
    hasFailures,
} from '../../../../extension/sw-sales-channel/agentic-commerce/prepare-flow';

const { Mixin } = Shopware;

/**
 * Settings > Agentic Commerce > Prepare sales channels.
 *
 * One route holding its own step state rather than three routes: the same
 * breadcrumb covers all three screens, and this plugin has already paid once for
 * the Vue Router 3 versus 4 split (see routes.init.js), so one registration is
 * one place for that to go wrong.
 *
 * Applying posts only the channel ids. Capabilities, transports and the profile
 * domain are the endpoint's defaults, which is why none of them are on screen.
 */
registerOrOverride('sw-settings-agentic-commerce-prepare', {
    template: useMtComponents() ? templateMt : templateSw,

    inject: ['ucpAdminApiService', 'acl'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            readiness: emptyReadiness(),
            step: STEP_UNDERSTAND,
            selectedIds: [],
            isLoading: true,
            isApplying: false,
            result: null,
        };
    },

    computed: {
        canPrepare() {
            return this.acl?.can?.('ucp.editor') === true;
        },

        steps() {
            return stepItems(this.step);
        },

        isUnderstandStep() {
            return this.step === STEP_UNDERSTAND;
        },

        isSelectStep() {
            return this.step === STEP_SELECT;
        },

        isReviewStep() {
            return this.step === STEP_REVIEW;
        },

        rows() {
            return prepareRows(this.readiness);
        },

        selectedRows() {
            return selectedRows(this.rows, this.selectedIds);
        },

        selectedCount() {
            return this.selectedIds.length;
        },

        canContinue() {
            return canLeaveStep(this.step, this.selectedIds);
        },

        applyDisabled() {
            return !this.canPrepare || this.isApplying || this.selectedIds.length === 0;
        },

        hasResult() {
            return this.result !== null;
        },

        outcomes() {
            return outcomesById(this.result);
        },

        findings() {
            return actionableOutcomeFindings(this.result);
        },

        hasFailures() {
            return hasFailures(this.result);
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
                    this.selectedIds = defaultPrepareSelection(this.rows);
                })
                .catch((error) => {
                    this.createNotificationError({ message: extractApiErrorMessage(error) });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        isSelected(id) {
            return this.selectedIds.includes(id);
        },

        onToggle(id, selected) {
            this.selectedIds = toggleSelection(this.selectedIds, id, selected);
        },

        selectAll() {
            this.selectedIds = defaultPrepareSelection(this.rows);
        },

        deselectAll() {
            this.selectedIds = [];
        },

        onNext() {
            if (!this.canContinue) {
                return;
            }

            this.step = nextStep(this.step);
        },

        onBack() {
            if (isFirstStep(this.step)) {
                this.onClose();

                return;
            }

            this.step = previousStep(this.step);
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
                .bulkEnable(buildPreparePayload(this.selectedIds))
                .then((response) => {
                    this.result = response?.data?.data ?? null;
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