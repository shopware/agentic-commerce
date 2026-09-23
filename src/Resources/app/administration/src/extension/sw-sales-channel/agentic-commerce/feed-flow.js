/**
 * The Choose / Review / Result flow that creates agentic feed channels.
 *
 * Pure functions over the readiness payload and the endpoint result, so the
 * page stays a thin renderer. A selection entry is one
 * `{ storefrontSalesChannelId, provider }` pair, the exact shape the endpoint
 * takes.
 */

const SNIPPET_ROOT = 'swagAgenticCommerce.feeds';

export const PROVIDER_OPEN_AI = 'open-ai';
export const PROVIDER_GOOGLE = 'google';

export const FEED_PROVIDERS = [
    {
        key: PROVIDER_OPEN_AI,
        mark: 'AI',
        labelKey: `${SNIPPET_ROOT}.providers.openAi.label`,
        descriptionKey: `${SNIPPET_ROOT}.providers.openAi.description`,
        format: 'JSONL',
    },
    {
        key: PROVIDER_GOOGLE,
        mark: 'G',
        labelKey: `${SNIPPET_ROOT}.providers.google.label`,
        descriptionKey: `${SNIPPET_ROOT}.providers.google.description`,
        format: 'XML',
    },
];

export const STEP_CHOOSE = 'choose';
export const STEP_REVIEW = 'review';
export const STEP_RESULT = 'result';

export const FEED_STEPS = [STEP_CHOOSE, STEP_REVIEW, STEP_RESULT];

export function providerByKey(key) {
    return FEED_PROVIDERS.find((provider) => provider.key === key) ?? null;
}

export function providerLabelKey(key) {
    return providerByKey(key)?.labelKey ?? `${SNIPPET_ROOT}.providers.unknown`;
}

export function feedStepItems(currentStep) {
    const current = Math.max(FEED_STEPS.indexOf(currentStep), 0);

    return FEED_STEPS.map((step, index) => {
        let state = 'todo';

        if (index < current || (step === STEP_RESULT && currentStep === STEP_RESULT)) {
            state = 'done';
        } else if (index === current) {
            state = 'active';
        }

        return { key: step, number: index + 1, state, label: `${SNIPPET_ROOT}.steps.${step}` };
    });
}

function feedsByProvider(feeds) {
    const list = Array.isArray(feeds) ? feeds : [];

    return FEED_PROVIDERS.reduce((carry, provider) => {
        carry[provider.key] = list.find((feed) => feed?.provider === provider.key) ?? null;

        return carry;
    }, {});
}

/**
 * One row per Storefront channel. Headless and feed channels cannot back a
 * product export, so they are never offered.
 */
export function feedRows(readiness) {
    const channels = Array.isArray(readiness?.channels) ? readiness.channels : [];

    return channels
        .filter((channel) => channel?.storefront === true && typeof channel?.id === 'string' && channel.id !== '')
        .map((channel) => {
            const domains = Array.isArray(channel.domains) ? channel.domains : [];

            return {
                id: channel.id,
                name: channel.name ?? '',
                primaryDomain: domains[0]?.url ?? null,
                hasDomain: domains.length > 0,
                feeds: feedsByProvider(channel.feeds),
            };
        });
}

export function isFeedSelectable(row, provider) {
    return row?.hasDomain === true && !row?.feeds?.[provider];
}

export function defaultFeedSelection(rows = []) {
    return rows.flatMap((row) => FEED_PROVIDERS
        .filter((provider) => isFeedSelectable(row, provider.key))
        .map((provider) => ({ storefrontSalesChannelId: row.id, provider: provider.key })));
}

export function isFeedSelected(selection = [], storefrontSalesChannelId, provider) {
    return selection.some((entry) => entry.storefrontSalesChannelId === storefrontSalesChannelId
        && entry.provider === provider);
}

export function toggleFeed(selection = [], storefrontSalesChannelId, provider, selected) {
    const next = selection.filter((entry) => !(entry.storefrontSalesChannelId === storefrontSalesChannelId
        && entry.provider === provider));

    if (selected) {
        next.push({ storefrontSalesChannelId, provider });
    }

    return next;
}

export function canLeaveFeedStep(step, selection = []) {
    return step === STEP_RESULT || selection.length > 0;
}

export function buildFeedPayload(selection = []) {
    return {
        feeds: selection.map((entry) => ({
            storefrontSalesChannelId: entry.storefrontSalesChannelId,
            provider: entry.provider,
        })),
    };
}

export function outcomeKey(outcome) {
    return `${outcome?.storefrontSalesChannelId ?? ''}:${outcome?.provider ?? ''}`;
}

/**
 * The selection in row order, with the names the review step shows.
 */
export function selectedFeedItems(rows = [], selection = []) {
    return rows.flatMap((row) => FEED_PROVIDERS
        .filter((provider) => isFeedSelected(selection, row.id, provider.key))
        .map((provider) => ({
            key: outcomeKey({ storefrontSalesChannelId: row.id, provider: provider.key }),
            storefrontName: row.name,
            providerLabelKey: provider.labelKey,
        })));
}

export function outcomesByKey(result) {
    const results = Array.isArray(result?.results) ? result.results : [];

    return results.reduce((carry, outcome) => {
        carry[outcomeKey(outcome)] = outcome;

        return carry;
    }, {});
}

/**
 * Result rows ready to render. The storefront name falls back to the readiness
 * rows, since a `not_found` outcome cannot name the channel it did not find.
 */
export function feedResultRows(result, rows = []) {
    const results = Array.isArray(result?.results) ? result.results : [];

    return results.map((outcome) => {
        const row = rows.find((entry) => entry.id === outcome?.storefrontSalesChannelId);

        return {
            key: outcomeKey(outcome),
            status: outcome?.status ?? 'failed',
            storefrontName: outcome?.storefrontSalesChannelName || row?.name || outcome?.storefrontSalesChannelId || '',
            providerLabelKey: providerLabelKey(outcome?.provider),
            salesChannelId: outcome?.salesChannelId ?? null,
            salesChannelName: outcome?.salesChannelName ?? '',
            feedUrl: outcome?.feedUrl ?? null,
            message: outcome?.message ?? '',
        };
    });
}

export function hasFailures(result) {
    return (result?.failed ?? 0) > 0;
}

/**
 * Every existing feed, flattened for the settings page overview.
 */
export function connectedFeeds(readiness) {
    return feedRows(readiness).flatMap((row) => FEED_PROVIDERS
        .filter((provider) => row.feeds[provider.key] !== null)
        .map((provider) => {
            const feed = row.feeds[provider.key];

            return {
                key: outcomeKey({ storefrontSalesChannelId: row.id, provider: provider.key }),
                storefrontName: row.name,
                providerKey: provider.key,
                providerLabelKey: provider.labelKey,
                salesChannelId: feed.salesChannelId ?? null,
                salesChannelName: feed.salesChannelName || row.name,
                feedUrl: feed.feedUrl ?? null,
                active: feed.active !== false,
            };
        }));
}

/**
 * True while some storefront with a domain still lacks a provider feed, which
 * keeps the settings task clickable after the first feed exists.
 */
export function hasMissingFeeds(readiness) {
    return defaultFeedSelection(feedRows(readiness)).length > 0;
}

/**
 * Rejects when the Clipboard API is missing (non-secure context), so the
 * caller can show one error path for both cases.
 */
export function copyText(text, clipboard = globalThis.navigator?.clipboard) {
    if (typeof text !== 'string' || text === '' || typeof clipboard?.writeText !== 'function') {
        return Promise.reject(new Error('Clipboard unavailable'));
    }

    return clipboard.writeText(text);
}
