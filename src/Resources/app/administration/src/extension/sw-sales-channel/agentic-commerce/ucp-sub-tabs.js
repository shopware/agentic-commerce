/**
 * Sub-tab definitions for the UCP card (rendered via mt-card's #tabs slot +
 * mt-tabs on 6.6+, or sw-tabs on 6.5). Pure: the component maps these to the
 * concrete tab-strip items and resolves labels with `$t`.
 */

const SNIPPET_ROOT = 'sw-sales-channel.detail.agenticCommerce.ucp';

export const SUB_TAB_EXPOSURE = 'exposure';
export const SUB_TAB_PREVIEW = 'preview';

export const DEFAULT_SUB_TAB = SUB_TAB_EXPOSURE;

export const UCP_SUB_TABS = [
    { name: SUB_TAB_EXPOSURE, label: `${SNIPPET_ROOT}.subTabExposure` },
    { name: SUB_TAB_PREVIEW, label: `${SNIPPET_ROOT}.subTabPreview` },
];

/**
 * Build the tab-strip items. When UCP is switched off, every tab except
 * Exposure is disabled (you flip the master toggle on Exposure first).
 *
 * @param {(key: string) => string} translate  e.g. component `$t`
 * @param {{ active?: boolean }} [options]
 * @returns {Array<{ name: string, label: string, disabled: boolean }>}
 */
export function buildSubTabItems(translate, { active = true } = {}) {
    return UCP_SUB_TABS.map((tab) => ({
        name: tab.name,
        label: typeof translate === 'function' ? translate(tab.label) : tab.label,
    })).filter((tab) => active || tab.name === SUB_TAB_EXPOSURE);
}

/**
 * Resolve a safe active tab — if the requested tab is disabled (UCP off),
 * fall back to Exposure.
 *
 * @param {string} requested
 * @param {{ active?: boolean }} [options]
 * @returns {string}
 */
export function resolveActiveSubTab(requested, { active = true } = {}) {
    if (!UCP_SUB_TABS.some((tab) => tab.name === requested)) {
        return DEFAULT_SUB_TAB;
    }

    if (!active && requested !== SUB_TAB_EXPOSURE) {
        return DEFAULT_SUB_TAB;
    }

    return requested;
}
