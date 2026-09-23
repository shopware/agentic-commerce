function loadInit({ canEdit = true, channels = [], dismissedEntry = null, currentUser = { id: 'user-1', admin: true } } = {}) {
    const push = jest.fn();
    const afterEach = jest.fn();
    const createNotification = jest.fn();
    const save = jest.fn(() => Promise.resolve());
    const router = { currentRoute: { value: { name: 'sw.dashboard.index' } }, push, afterEach };
    const getSalesChannels = jest.fn(() => Promise.resolve({ data: { data: channels } }));
    const search = jest.fn(() => Promise.resolve({ first: () => dismissedEntry }));
    const sessionState = { currentUser };

    globalThis.Shopware = {
        ...globalThis.Shopware,
        Context: { api: {} },
        Data: {
            Criteria: class {
                addFilter() {}

                static equals(field, value) {
                    return { field, value };
                }
            },
        },
        Store: {
            get(id) {
                if (id === 'notification') {
                    return { createNotification };
                }
                throw new Error(`Store with id "${id}" not found`);
            },
        },
        Application: {
            viewInitialized: new Promise(() => {}),
            view: { router },
            getApplicationRoot: () => ({ config: { globalProperties: { $t: (key) => key } } }),
        },
        State: { get: () => sessionState },
        Service: jest.fn((name) => {
            if (name === 'acl') {
                return { can: (privilege) => (privilege === 'ucp.editor' ? canEdit : true) };
            }
            if (name === 'ucpAdminApiService') {
                return { getSalesChannels };
            }
            if (name === 'repositoryFactory') {
                return { create: () => ({ search, save }) };
            }

            return null;
        }),
    };

    let init;
    jest.isolateModules(() => {
        // The module arms the boot hook as an import side effect.
        init = require('Resources/extension/sw-sales-channel/onboarding.init');
    });

    return { ...init, router, push, afterEach, getSalesChannels, search, sessionState, createNotification };
}

const unexposed = [{ id: 'storefront', name: 'Storefront', ucp: { active: false }, domains: [] }];
const exposed = [{ id: 'storefront', name: 'Storefront', ucp: { active: true }, domains: [] }];

describe('onboarding.init', () => {
    it('offers the settings page without navigating there uninvited', async () => {
        const { offerOnboarding, router, push, createNotification } = loadInit({ channels: unexposed });

        await expect(offerOnboarding(router)).resolves.toBe(true);
        expect(createNotification).toHaveBeenCalledTimes(1);
        expect(push).not.toHaveBeenCalled();

        const notification = createNotification.mock.calls[0][0];
        expect(notification.autoClose).toBe(false);
        expect(notification.actions[0].route).toEqual({ name: 'sw.settings.agentic.commerce.index' });
        expect(typeof notification.actions[1].method).toBe('function');
    });

    it('says nothing once a channel is exposed', async () => {
        const { offerOnboarding, router, createNotification } = loadInit({ channels: exposed });

        await expect(offerOnboarding(router)).resolves.toBe(false);
        expect(createNotification).not.toHaveBeenCalled();
    });

    it('retries on a later navigation while the session has no user yet', async () => {
        const { offerOnboarding, router, createNotification, sessionState, getSalesChannels } = loadInit({ channels: unexposed, currentUser: null });

        await expect(offerOnboarding(router)).resolves.toBe(false);
        expect(getSalesChannels).not.toHaveBeenCalled();

        // ACL answers false for everything until the session carries a user, so the
        // first navigation must not burn the single evaluation.
        sessionState.currentUser = { id: 'user-1', admin: true };

        await expect(offerOnboarding(router)).resolves.toBe(true);
        expect(createNotification).toHaveBeenCalledTimes(1);
    });

    it('never calls the API for a user who cannot edit UCP config', async () => {
        const { offerOnboarding, router, getSalesChannels, search } = loadInit({ canEdit: false, channels: unexposed });

        await expect(offerOnboarding(router)).resolves.toBe(false);
        expect(getSalesChannels).not.toHaveBeenCalled();
        expect(search).not.toHaveBeenCalled();
    });

    it('does not fetch channels for a user who already dismissed the offer', async () => {
        const { offerOnboarding, router, getSalesChannels } = loadInit({
            channels: unexposed,
            dismissedEntry: { value: [true] },
        });

        await expect(offerOnboarding(router)).resolves.toBe(false);
        expect(getSalesChannels).not.toHaveBeenCalled();
    });

    it('evaluates once even when every navigation runs it', async () => {
        const { offerOnboarding, router, createNotification, getSalesChannels } = loadInit({ channels: unexposed });

        await offerOnboarding(router);
        await offerOnboarding(router);
        await offerOnboarding(router);

        expect(createNotification).toHaveBeenCalledTimes(1);
        expect(getSalesChannels).toHaveBeenCalledTimes(1);
    });

    it('does not offer the page the user is already looking at', async () => {
        const { offerOnboarding, router, createNotification } = loadInit({ channels: unexposed });
        router.currentRoute.value.name = 'sw.settings.agentic.commerce.index';

        await expect(offerOnboarding(router)).resolves.toBe(false);
        expect(createNotification).not.toHaveBeenCalled();
    });

    it('subscribes to navigation rather than checking once at view init', () => {
        const { installOnboardingHook, router, afterEach } = loadInit();

        expect(installOnboardingHook(router)).toBe(true);
        expect(afterEach).toHaveBeenCalledTimes(1);
    });

    it('stays silent when the router is not available yet', () => {
        const { installOnboardingHook } = loadInit();

        expect(installOnboardingHook(undefined)).toBe(false);
    });
});