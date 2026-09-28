import { createAdminNotification } from 'Resources/extension/sw-sales-channel/agentic-commerce/admin-notification';
import { translateAdmin } from 'Resources/extension/sw-sales-channel/agentic-commerce/admin-i18n';

function throwingStore() {
    return {
        get(id) {
            throw new Error(`Store with id "${id}" not found`);
        },
    };
}

describe('admin notification and translation adapters', () => {
    afterEach(() => {
        delete globalThis.Shopware.Store;
        delete globalThis.Shopware.State;
        delete globalThis.Shopware.Application;
    });

    it('raises through the Pinia store when it is registered', () => {
        const createNotification = jest.fn(() => 'uuid-1');
        globalThis.Shopware.Store = { get: () => ({ createNotification }) };

        expect(createAdminNotification({ title: 'hi' })).toBe('uuid-1');
        expect(createNotification).toHaveBeenCalledWith({ title: 'hi' });
    });

    it('falls back to a Vuex dispatch when the Pinia registry throws for the id', () => {
        const dispatch = jest.fn(() => 'uuid-2');
        globalThis.Shopware.Store = throwingStore();
        globalThis.Shopware.State = { dispatch };

        expect(createAdminNotification({ title: 'hi' })).toBe('uuid-2');
        expect(dispatch).toHaveBeenCalledWith('notification/createNotification', { title: 'hi' });
    });

    it('stays silent when neither store can raise anything', () => {
        globalThis.Shopware.Store = throwingStore();

        expect(createAdminNotification({ title: 'hi' })).toBeNull();
    });

    it('translates through the Vue 3 app global properties', () => {
        globalThis.Shopware.Application = {
            getApplicationRoot: () => ({ config: { globalProperties: { $t: (key) => `translated:${key}` } } }),
        };

        expect(translateAdmin('a.b')).toBe('translated:a.b');
    });

    it('translates through a Vue 2 root instance', () => {
        globalThis.Shopware.Application = {
            getApplicationRoot: () => ({ $tc: (key) => `legacy:${key}` }),
        };

        expect(translateAdmin('a.b')).toBe('legacy:a.b');
    });

    it('returns the key rather than throwing inside a navigation guard', () => {
        globalThis.Shopware.Application = { getApplicationRoot: () => false };
        expect(translateAdmin('a.b')).toBe('a.b');

        globalThis.Shopware.Application = {
            getApplicationRoot: () => ({ config: { globalProperties: { $t: () => { throw new Error('boom'); } } } }),
        };
        expect(translateAdmin('a.b')).toBe('a.b');
    });
});