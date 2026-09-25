import { readAdminStore } from './admin-store';

/**
 * Raises an administration notification from outside a component.
 *
 * The notification mixin is not available in a router hook, and the store moved
 * from a Vuex module to a Pinia store across the supported lanes: Pinia exposes
 * `createNotification` on the store, Vuex only through a dispatch.
 */
export function createAdminNotification(notification) {
    const store = readAdminStore('notification');
    if (typeof store?.createNotification === 'function') {
        return store.createNotification(notification);
    }

    try {
        if (typeof Shopware.State?.dispatch === 'function') {
            return Shopware.State.dispatch('notification/createNotification', notification);
        }
    } catch {
        // Nothing can raise it; the banners remain the way in.
    }

    return null;
}