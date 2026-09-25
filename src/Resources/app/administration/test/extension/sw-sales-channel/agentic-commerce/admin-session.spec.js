import {
    adminSession,
    currentAdminUserId,
    isAdminLoggedIn,
} from 'Resources/extension/sw-sales-channel/agentic-commerce/admin-session';

const user = { id: 'user-1', admin: true };

function throwingStore() {
    return {
        get(id) {
            throw new Error(`Store with id "${id}" not found`);
        },
    };
}

describe('admin-session', () => {
    afterEach(() => {
        delete globalThis.Shopware.Store;
        delete globalThis.Shopware.State;
    });

    it('falls back to Vuex when the Pinia registry throws for an unknown id', () => {
        // Shopware.Store.get() throws rather than returning undefined, so a plain
        // `?? ` chain propagates out of the caller instead of falling through.
        globalThis.Shopware.Store = throwingStore();
        globalThis.Shopware.State = { get: () => ({ currentUser: user }) };

        expect(adminSession()).toEqual({ currentUser: user });
        expect(currentAdminUserId()).toBe('user-1');
        expect(isAdminLoggedIn()).toBe(true);
    });

    it('reads the Pinia session when it is registered', () => {
        globalThis.Shopware.Store = { get: () => ({ currentUser: user }) };

        expect(currentAdminUserId()).toBe('user-1');
    });

    it('reports nobody logged in when neither store has a session', () => {
        globalThis.Shopware.Store = throwingStore();
        globalThis.Shopware.State = { get: () => null };

        expect(adminSession()).toBeNull();
        expect(currentAdminUserId()).toBeNull();
        expect(isAdminLoggedIn()).toBe(false);
    });

    it('reports nobody logged in when both stores throw', () => {
        globalThis.Shopware.Store = throwingStore();
        globalThis.Shopware.State = throwingStore();

        expect(isAdminLoggedIn()).toBe(false);
    });

    it('reports nobody logged in while the session carries no user yet', () => {
        globalThis.Shopware.State = { get: () => ({ currentUser: null }) };

        expect(isAdminLoggedIn()).toBe(false);
    });
});