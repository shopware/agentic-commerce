/**
 * Reads an administration store across the Vuex and Pinia registries.
 *
 * `Shopware.Store.get(id)` THROWS for an id it does not know rather than
 * returning undefined, so `Shopware.Store?.get?.(id) ?? Shopware.State?.get?.(id)`
 * does not fall through on a lane that has the Pinia registry but keeps that
 * store in Vuex: it throws out of the caller, and inside a router hook or a
 * module registration that surfaces as nothing at all. Every read is guarded
 * individually and an unreadable store is reported as null.
 */
export function readAdminStore(id) {
    const readers = [
        () => Shopware.Store?.get?.(id),
        () => Shopware.State?.get?.(id),
    ];

    for (const read of readers) {
        try {
            const store = read();
            if (store) {
                return store;
            }
        } catch {
            // Registry without this id on this lane; try the next one.
        }
    }

    return null;
}