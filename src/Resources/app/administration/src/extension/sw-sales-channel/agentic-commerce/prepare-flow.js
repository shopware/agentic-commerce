/**
 * The Understand / Select / Review flow that prepares sales channels.
 *
 * Kept as pure functions so the step machine, the payload and the result
 * summary are testable without mounting the page. The page itself only holds
 * `step`, `selectedIds` and the response.
 */

export const STEP_UNDERSTAND = 'understand';
export const STEP_SELECT = 'select';
export const STEP_REVIEW = 'review';

export const PREPARE_STEPS = [STEP_UNDERSTAND, STEP_SELECT, STEP_REVIEW];

export function stepIndex(step) {
    const index = PREPARE_STEPS.indexOf(step);

    return index === -1 ? 0 : index;
}

export function nextStep(step) {
    return PREPARE_STEPS[Math.min(stepIndex(step) + 1, PREPARE_STEPS.length - 1)];
}

export function previousStep(step) {
    return PREPARE_STEPS[Math.max(stepIndex(step) - 1, 0)];
}

export function isFirstStep(step) {
    return stepIndex(step) === 0;
}

/**
 * 'done' for a step already passed, 'active' for the current one, 'todo' ahead.
 */
export function stepState(step, currentStep) {
    const position = stepIndex(step);
    const current = stepIndex(currentStep);

    if (position < current) {
        return 'done';
    }

    return position === current ? 'active' : 'todo';
}

export function stepItems(currentStep) {
    return PREPARE_STEPS.map((step, index) => ({
        key: step,
        number: index + 1,
        state: stepState(step, currentStep),
        label: `swagAgenticCommerce.prepare.steps.${step}`,
    }));
}

/**
 * Only the channel ids travel. Capabilities and transports are deliberately
 * omitted so the endpoint applies its own defaults, which is why the merchant
 * never sees a capability picker.
 */
export function buildPreparePayload(selectedIds = []) {
    return { salesChannelIds: [...selectedIds] };
}

export function canLeaveStep(step, selectedIds = []) {
    if (step === STEP_SELECT || step === STEP_REVIEW) {
        return selectedIds.length > 0;
    }

    return true;
}

export function toggleSelection(selectedIds = [], id, selected) {
    const next = selectedIds.filter((entry) => entry !== id);

    if (selected) {
        next.push(id);
    }

    return next;
}

export function selectedRows(rows = [], selectedIds = []) {
    return rows.filter((row) => selectedIds.includes(row.id));
}

/**
 * Index the endpoint's per-channel outcomes by id so a row can show its own
 * result without scanning the list.
 */
export function outcomesById(result) {
    const results = Array.isArray(result?.results) ? result.results : [];

    return results.reduce((carry, outcome) => {
        if (typeof outcome?.salesChannelId === 'string') {
            carry[outcome.salesChannelId] = outcome;
        }

        return carry;
    }, {});
}

/**
 * Findings worth showing after a run. INFO notes are dropped: they would bury
 * the ones that need an action.
 */
export function actionableOutcomeFindings(result) {
    const results = Array.isArray(result?.results) ? result.results : [];

    return results.flatMap((outcome) => {
        const findings = Array.isArray(outcome?.findings) ? outcome.findings : [];

        return findings.filter((finding) => finding?.severity === 'warning' || finding?.severity === 'error');
    });
}

export function hasFailures(result) {
    return (result?.failed ?? 0) > 0;
}