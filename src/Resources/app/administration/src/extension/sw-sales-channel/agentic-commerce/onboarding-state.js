/**
 * Whether this shop still needs to be offered onboarding, and where to send a
 * merchant who accepts.
 *
 * Derived from the sales-channel list rather than stored, so a shop that removes
 * its UCP setup is offered the flow again. The only persisted piece is the
 * per-user dismissal in onboarding-dismissal.js.
 */

const DISMISSAL_KEY = 'swag-agentic-commerce.onboarding.dismissed';

export const ONBOARDING_DISMISSAL_KEY = DISMISSAL_KEY;

export const SETTINGS_ROUTE = 'sw.settings.agentic.commerce.index';

/**
 * Onboarding is pending while no channel exposes UCP.
 *
 * @param {Array<{ ucp?: { active?: boolean } }>} channels
 */
export function needsOnboarding(channels = []) {
    if (!Array.isArray(channels) || channels.length === 0) {
        return false;
    }

    return !channels.some((channel) => channel?.ucp?.active === true);
}

/**
 * Whether the boot hook should send the merchant to the settings page.
 *
 * Kept pure so the decision is testable without a router: the caller supplies
 * the state, and separately remembers that it already offered once so an
 * in-session route change cannot re-trigger it.
 *
 * @param {{ dismissed?: boolean, channels?: Array<object>, currentRouteName?: string, alreadyOffered?: boolean }} state
 */
export function shouldOfferOnboarding({ dismissed = false, channels = [], currentRouteName = null, alreadyOffered = false } = {}) {
    if (alreadyOffered || dismissed) {
        return false;
    }

    // Already looking at the page the offer would send them to.
    if (currentRouteName === SETTINGS_ROUTE) {
        return false;
    }

    return needsOnboarding(channels);
}