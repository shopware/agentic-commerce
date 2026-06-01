import { UCP_VERSION } from '../../ucp-protocol.js';

export function defaultForm() {
    return {
        active: false,
        ucpVersion: UCP_VERSION,
        profileUriStrategy: 'domain',
        customProfileUri: null,
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

export function normalizeConfig(config) {
    const fallback = defaultForm();

    return {
        ...fallback,
        ...config,
        enabledCapabilities: Array.isArray(config.enabledCapabilities) ? config.enabledCapabilities : fallback.enabledCapabilities,
        enabledTransports: Array.isArray(config.enabledTransports) ? config.enabledTransports : fallback.enabledTransports,
        platformAllowlist: Array.isArray(config.platformAllowlist) ? config.platformAllowlist : fallback.platformAllowlist,
        remoteProfileAllowlist: Array.isArray(config.remoteProfileAllowlist) ? config.remoteProfileAllowlist : fallback.remoteProfileAllowlist,
        agentAllowlist: Array.isArray(config.agentAllowlist) ? config.agentAllowlist : fallback.agentAllowlist,
        embeddedAllowedOrigins: Array.isArray(config.embeddedAllowedOrigins) ? config.embeddedAllowedOrigins : fallback.embeddedAllowedOrigins,
        embeddedFrameAncestors: Array.isArray(config.embeddedFrameAncestors) ? config.embeddedFrameAncestors : fallback.embeddedFrameAncestors,
        customProfileUri: config.customProfileUri || null,
        continueUrlTemplate: config.continueUrlTemplate || null,
        webhookUrlOverride: config.webhookUrlOverride || null,
    };
}

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
