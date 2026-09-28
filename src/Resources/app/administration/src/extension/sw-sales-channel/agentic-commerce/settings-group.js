import { readAdminStore } from './admin-store';
import { currentAdminVersion, isVersionAtLeast } from './admin-version';

/**
 * Which Settings group the Agentic Commerce item belongs to.
 *
 * The groups differ by lane: `commerce` exists from 6.6 on, and older lanes keep
 * shop-level items under `shop`. The store creates an unknown group on demand
 * rather than rejecting it, so picking the wrong one does not error, it just
 * files the item under a heading nothing else uses.
 *
 * Probing the store is the accurate signal, but module registration can run
 * before the store exists, so the version is the fallback. Both answers agree on
 * every supported lane.
 */

export const GROUP_COMMERCE = 'commerce';
export const GROUP_SHOP = 'shop';

export function settingsGroup() {
    const groups = readAdminStore('settingsItems')?.settingsGroups;

    if (groups && typeof groups === 'object') {
        return Object.prototype.hasOwnProperty.call(groups, GROUP_COMMERCE) ? GROUP_COMMERCE : GROUP_SHOP;
    }

    return isVersionAtLeast(currentAdminVersion(), 6, 6) ? GROUP_COMMERCE : GROUP_SHOP;
}