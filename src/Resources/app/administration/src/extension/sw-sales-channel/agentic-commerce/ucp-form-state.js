import { UCP_VERSION } from './ucp-protocol.js';

/**
 * Default UCP config form state.
 *
 * Note: `profileUriStrategy` / `customProfileUri` were removed (redesign §10.5).
 * The profile is always served from a configured sales-channel domain, so the
 * UI exposes a domain selector instead of a free-text URI. The selected domain
 * is not part of the persisted config payload; it only drives the live preview
 * base URI (see ucp-profile-preview.js).
 */
export function defaultForm() {
    return {
        active: false,
        ucpVersion: UCP_VERSION,
        profileDomain: null,
        enabledCapabilities: ['catalog', 'cart', 'discount', 'checkout', 'order'],
        enabledTransports: ['rest'],
        continueUrlTemplate: null,
        platformAllowlist: [],
        remoteProfileAllowlist: [],
        agentAllowlist: [],
        embeddedAllowedOrigins: [],
        embeddedFrameAncestors: [],
        discoveryBudget: 10,
        webhookUrlOverride: null,
        signaturePolicy: 'strict',
        idempotencyRequired: true,
    };
}

const ARRAY_FIELDS = [
    'enabledCapabilities',
    'enabledTransports',
    'platformAllowlist',
    'remoteProfileAllowlist',
    'agentAllowlist',
    'embeddedAllowedOrigins',
    'embeddedFrameAncestors',
];

const NULLABLE_STRING_FIELDS = [
    'profileDomain',
    'continueUrlTemplate',
    'webhookUrlOverride',
];

/**
 * Merge a (possibly partial / legacy) config payload onto the defaults,
 * coercing array fields to arrays and nullable strings to `null`. Unknown keys
 * (e.g. legacy `profileUriStrategy`) are dropped.
 *
 * @param {Record<string, unknown>} config
 */
export function normalizeConfig(config = {}) {
    const fallback = defaultForm();
    const merged = { ...fallback };

    Object.keys(fallback).forEach((key) => {
        if (config[key] === undefined) {
            return;
        }
        merged[key] = config[key];
    });

    ARRAY_FIELDS.forEach((field) => {
        merged[field] = Array.isArray(config[field]) ? config[field] : fallback[field];
    });

    NULLABLE_STRING_FIELDS.forEach((field) => {
        merged[field] = config[field] || null;
    });

    return merged;
}

/**
 * Build the payload sent to the save endpoint — a defensive copy of the form
 * with array fields cloned so the caller can't mutate component state.
 *
 * @param {Record<string, unknown>} form
 */
export function buildConfigPayload(form) {
    const payload = { ...form };

    ARRAY_FIELDS.forEach((field) => {
        payload[field] = Array.isArray(form[field]) ? [...form[field]] : [];
    });

    return payload;
}

/**
 * Immutably add/remove a value from an array (checkbox/toggle helper).
 *
 * @param {Array} values
 * @param {*} entry
 * @param {boolean} enabled
 */
export function toggleArrayValue(values, entry, enabled) {
    const nextValues = [...values];
    const existingIndex = nextValues.indexOf(entry);

    if (enabled && existingIndex === -1) {
        nextValues.push(entry);
    }

    if (!enabled && existingIndex !== -1) {
        nextValues.splice(existingIndex, 1);
    }

    return nextValues;
}
