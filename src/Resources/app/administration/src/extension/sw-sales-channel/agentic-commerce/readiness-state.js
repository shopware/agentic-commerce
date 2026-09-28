/**
 * View model for the Agentic Commerce settings page.
 *
 * Everything here is a pure function of the `readiness` payload, so the page
 * component stays a thin renderer and the rules that decide what a merchant is
 * told are testable without mounting anything.
 */

const SNIPPET_ROOT = 'swagAgenticCommerce.settings';

export const STATUS_SETUP_NEEDED = 'setup_needed';
export const STATUS_ACTION_NEEDED = 'action_needed';
export const STATUS_READY = 'ready';

export const TASK_INSTALLED = 'installed';
export const TASK_PREPARED = 'prepared';
export const TASK_CONNECTED = 'connected';

export function emptyReadiness() {
    return {
        status: STATUS_SETUP_NEEDED,
        completedSteps: 0,
        totalSteps: 3,
        steps: {
            installed: { done: false },
            prepared: { done: false, count: 0 },
            connected: { done: false, count: 0 },
        },
        channels: [],
    };
}

export function normalizeReadiness(payload) {
    const fallback = emptyReadiness();

    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    return {
        ...fallback,
        ...payload,
        steps: { ...fallback.steps, ...(payload.steps ?? {}) },
        channels: Array.isArray(payload.channels) ? payload.channels : [],
    };
}

export function isReady(readiness) {
    return readiness?.status === STATUS_READY;
}

/**
 * The pill reads `success` only when the backend says every step is done AND no
 * channel has an error-level finding. Deliberately not derived from the step
 * count alone: an exposed channel with no domain or no signing key completes the
 * step and still cannot be reached by any agent.
 */
export function statusVariant(readiness) {
    return isReady(readiness) ? 'success' : 'warning';
}

export function statusSnippet(readiness) {
    const status = readiness?.status ?? STATUS_SETUP_NEEDED;

    return {
        heading: `${SNIPPET_ROOT}.status.${status}.heading`,
        copy: `${SNIPPET_ROOT}.status.${status}.copy`,
        pill: `${SNIPPET_ROOT}.status.${status}.pill`,
    };
}

export function progressPercent(readiness) {
    const total = readiness?.totalSteps || 3;
    const completed = Math.min(readiness?.completedSteps ?? 0, total);

    return Math.round((completed / total) * 100);
}

/**
 * The three rows of the readiness list. `connected` stays locked until at least
 * one channel is prepared, because an Agentic Commerce channel feeds from a
 * prepared storefront and creating one first leads nowhere.
 */
export function readinessTasks(readiness) {
    const steps = readiness?.steps ?? emptyReadiness().steps;
    const preparedDone = steps.prepared?.done === true;

    return [
        {
            key: TASK_INSTALLED,
            done: steps.installed?.done === true,
            locked: false,
            count: null,
            pending: 0,
        },
        {
            key: TASK_PREPARED,
            done: preparedDone,
            locked: false,
            count: steps.prepared?.count ?? 0,
            // Channels added after the first run are still preparable, so the
            // step stays actionable even once it counts as done.
            pending: unpreparedChannels(readiness).length,
        },
        {
            key: TASK_CONNECTED,
            done: steps.connected?.done === true,
            locked: !preparedDone,
            count: steps.connected?.count ?? 0,
            pending: 0,
        },
    ];
}

/**
 * Which description a task row shows. A prepared step with channels still
 * waiting gets its own line rather than the plain "done" one, otherwise a
 * merchant who adds a storefront later is told everything is complete.
 */
export function taskDescriptionKey(task) {
    if (task?.key === TASK_PREPARED && task?.done && (task?.pending ?? 0) > 0) {
        return `${SNIPPET_ROOT}.tasks.prepared.pendingDescription`;
    }

    return `${SNIPPET_ROOT}.tasks.${task?.key}.${task?.done ? 'doneDescription' : 'description'}`;
}

export function taskActionKey(task) {
    return task?.done
        ? `${SNIPPET_ROOT}.tasks.${task?.key}.actionMore`
        : `${SNIPPET_ROOT}.tasks.${task?.key}.action`;
}

export function preparedChannels(readiness) {
    return (readiness?.channels ?? []).filter((channel) => channel?.prepared === true);
}

export function unpreparedChannels(readiness) {
    return (readiness?.channels ?? []).filter((channel) => channel?.prepared !== true);
}

/**
 * Findings across every prepared channel that a merchant can act on. INFO notes
 * are dropped: they would bury the ones that need attention.
 */
export function actionableChannelFindings(readiness) {
    return (readiness?.channels ?? []).flatMap((channel) => {
        const findings = Array.isArray(channel?.findings) ? channel.findings : [];

        return findings
            .filter((finding) => finding?.severity === 'warning' || finding?.severity === 'error')
            .map((finding) => ({ ...finding, salesChannelName: finding.salesChannelName || channel?.name || '' }));
    });
}

export function hasChannelsToPrepare(readiness) {
    return unpreparedChannels(readiness).length > 0;
}

/**
 * The rows the prepare flow offers.
 *
 * Only unprepared channels appear: a channel that already has UCP and its AI
 * files is nothing for this flow to do, and offering it invites a merchant to
 * rewrite a configuration they tuned.
 */
export function prepareRows(readiness) {
    return unpreparedChannels(readiness)
        .map((channel) => {
            const domains = Array.isArray(channel?.domains) ? channel.domains : [];

            return {
                id: channel?.id,
                name: channel?.name ?? '',
                activeProductCount: channel?.activeProductCount ?? 0,
                domainCount: domains.length,
                hasDomain: domains.length > 0,
                primaryDomain: domains[0]?.url ?? null,
            };
        })
        .filter((row) => typeof row.id === 'string' && row.id !== '');
}

export function defaultPrepareSelection(rows = []) {
    return rows.map((row) => row.id);
}