import { buildConfigPayload } from './ucp-form-state.js';

/**
 * Live profile preview helpers (redesign §10.4). The preview re-renders from the
 * current edited form state, not just after save, so the merchant sees pending
 * edits. A dirty flag drives the "Preview is not saved yet" banner.
 */

/**
 * Build the request payload for the live-preview endpoint: the edited config
 * plus the selected profile domain (which drives the base URI, §10.5).
 *
 * @param {Record<string, unknown>} form
 * @param {{ profileDomain?: string|null }} [options]
 */
export function buildPreviewPayload(form, { profileDomain = null } = {}) {
    return {
        config: buildConfigPayload(form),
        profileDomain: profileDomain || null,
    };
}

/**
 * Whether the current form differs from the last saved form (drives the unsaved
 * banner). Order-independent on the config object; uses a stable stringify.
 *
 * @param {Record<string, unknown>} savedForm
 * @param {Record<string, unknown>} currentForm
 * @returns {boolean}
 */
export function isPreviewDirty(savedForm, currentForm) {
    return stableStringify(savedForm) !== stableStringify(currentForm);
}

function stableStringify(value) {
    return JSON.stringify(value, replacer);
}

function replacer(key, value) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return Object.keys(value)
            .sort()
            .reduce((sorted, objectKey) => {
                sorted[objectKey] = value[objectKey];
                return sorted;
            }, {});
    }

    return value;
}

/**
 * The UCP metadata block of a rendered profile (`preview.ucp` or the preview
 * itself for older shapes).
 *
 * @param {Record<string, unknown>|null} preview
 */
export function extractProfileMetadata(preview) {
    if (!preview) {
        return {};
    }

    return preview.ucp || preview;
}

/**
 * Names of the capabilities advertised in a rendered profile.
 *
 * @param {Record<string, unknown>|null} preview
 * @returns {string[]}
 */
export function profileCapabilityNames(preview) {
    const metadata = extractProfileMetadata(preview);

    if (!metadata.capabilities) {
        return [];
    }

    return Object.keys(metadata.capabilities);
}

/**
 * Total count of service endpoints advertised across all services.
 *
 * @param {Record<string, unknown>|null} preview
 * @returns {number}
 */
export function serviceEndpointCount(preview) {
    const metadata = extractProfileMetadata(preview);

    if (!metadata.services) {
        return 0;
    }

    return Object.values(metadata.services).reduce((count, endpoints) => {
        return count + (Array.isArray(endpoints) ? endpoints.length : 0);
    }, 0);
}
