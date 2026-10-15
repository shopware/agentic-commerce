/**
 * Cross-version helpers for the Agentic Commerce admin.
 *
 * The plugin supports Shopware 6.5 through 6.7+. The Meteor (`mt-*`) component
 * set this UI relies on (mt-card #tabs/#headerRight slots, mt-tabs items API,
 * mt-switch, mt-banner, mt-select, mt-checkbox) ships with core from 6.6.2.0;
 * 6.6.0 and 6.6.1 have no Meteor library at all. Before that we fall back to the
 * legacy `sw-*` templates.
 *
 * Everything here is pure so it can be unit tested without mounting Vue or
 * booting the admin: pass a version string in, get a decision out.
 */

/**
 * Parse the leading `major.minor` out of a Shopware version string.
 * Handles release versions ("6.6.10.0") and the dev/trunk marker
 * ("6.7.9999999.9999999").
 *
 * @param {unknown} version
 * @returns {{ major: number, minor: number } | null}
 */
export function parseMajorMinor(version) {
    if (typeof version !== 'string') {
        return null;
    }

    const match = version.match(/^(\d+)\.(\d+)/);

    if (!match) {
        return null;
    }

    return { major: Number(match[1]), minor: Number(match[2]) };
}

/**
 * True when `version` is at least `major.minor[.patch]`. Unparseable input is
 * treated as "older" (returns false) so we degrade to the safe legacy path.
 *
 * @param {unknown} version
 * @param {number} major
 * @param {number} minor
 * @param {number} [patch]
 * @returns {boolean}
 */
export function isVersionAtLeast(version, major, minor, patch = 0) {
    const parsed = parseMajorMinor(version);

    if (!parsed) {
        return false;
    }

    if (parsed.major !== major) {
        return parsed.major > major;
    }

    if (parsed.minor !== minor) {
        return parsed.minor > minor;
    }

    return Number(version.split('.')[2] ?? 0) >= patch;
}

/**
 * The running admin's Shopware version, or an empty string when unavailable.
 *
 * @returns {string}
 */
export function currentAdminVersion() {
    return Shopware?.Context?.app?.config?.version ?? '';
}

/**
 * Whether to render the Meteor (`mt-*`) templates. 6.6.2+ → true, earlier → false.
 * Defaults to the running admin version but accepts an explicit version for
 * testing.
 *
 * @param {string} [version]
 * @returns {boolean}
 */
export function useMtComponents(version = currentAdminVersion()) {
    return isVersionAtLeast(version, 6, 6, 2);
}
