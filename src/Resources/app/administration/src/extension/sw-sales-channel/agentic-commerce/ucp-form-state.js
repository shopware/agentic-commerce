
/**
 * Default UCP config form state — the Exposure-tab surface only.
 *
 * Signature policy, signing keys and the remaining host/delivery settings are
 * managed via console commands (`ucp:config:*` / `ucp:signing-keys:*`). Those
 * fields still live in the persisted `UcpConfig`; the admin save merges this
 * Exposure-only payload over the stored config server-side, so console-set
 * values are preserved (see UcpConfigService::saveConfig). The platform policy
 * and allowlist are here because SaaS merchants cannot run console commands:
 * `all` accepts every platform with a valid profile, `listed` only the allowlist.
 */
export function defaultForm() {
    return {
        active: false,
        profileDomain: null,
        enabledCapabilities: ['catalog', 'cart', 'discount', 'checkout', 'order'],
        enabledTransports: ['rest'],
        platformPolicy: 'all',
        platformAllowlist: [],
    };
}

export const PLATFORM_POLICIES = ['all', 'listed'];

const ARRAY_FIELDS = [
    'enabledCapabilities',
    'enabledTransports',
    'platformAllowlist',
];

// Same shape UcpConfig::normalizeHost() accepts for names and IPv4 addresses.
const HOST_PATTERN = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

const NULLABLE_STRING_FIELDS = [
    'profileDomain',
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

    if (!PLATFORM_POLICIES.includes(merged.platformPolicy)) {
        merged.platformPolicy = fallback.platformPolicy;
    }

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
 * Reduce an allowlist entry to a bare lower-case host. Merchants often paste the
 * platform's profile URL, so a URL is cut down to its host name.
 *
 * @param {unknown} entry
 */
export function normalizeHostEntry(entry) {
    const value = String(entry ?? '').trim().toLowerCase();

    if (!value.includes('://')) {
        return value.replace(/\.$/, '');
    }

    try {
        return new URL(value).hostname.replace(/\.$/, '');
    } catch {
        return value;
    }
}

/**
 * @param {unknown} entry
 */
export function isValidHostEntry(entry) {
    const host = normalizeHostEntry(entry);

    // One label such as "com" would allow every host under that top-level domain.
    return host.length <= 253 && host.includes('.') && HOST_PATTERN.test(host);
}

/**
 * @param {Array} values
 */
export function normalizeHostList(values) {
    return [...new Set(values.map(normalizeHostEntry).filter(Boolean))];
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
