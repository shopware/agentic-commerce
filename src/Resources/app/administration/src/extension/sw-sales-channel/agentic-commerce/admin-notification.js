/**
 * Raises an administration notification from outside a component.
 *
 * The notification mixin is not available in a router hook, and the store moved
 * from a Vuex module to a Pinia store across the supported lanes: Pinia exposes
 * `createNotification` on the store, Vuex only through a dispatch. Both paths
 * are guarded because `Shopware.Store.get(id)` throws for an id it does not
 * know (see admin-store.js).
 */
export function createAdminNotification(notification) {
    try {
        const store = Shopware.Store?.get?.('notification');
        if (store && typeof store.createNotification === 'function') {
            return store.createNotification(notification);
        }
    } catch {
        // Pinia registry without this store on this lane; fall through to Vuex.
    }

    try {
        if (typeof Shopware.State?.dispatch === 'function') {
            return Shopware.State.dispatch('notification/createNotification', notification);
        }
    } catch {
        // Nothing can be raised; the banners remain the way in.
    }

    return null;
}