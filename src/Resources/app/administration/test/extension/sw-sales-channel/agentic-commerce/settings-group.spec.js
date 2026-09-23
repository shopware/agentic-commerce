function loadSettingsGroup({ store = undefined, state = undefined, version = '6.6.10.0' } = {}) {
    globalThis.Shopware = {
        ...globalThis.Shopware,
        Store: store,
        State: state,
        Context: { app: { config: { version } } },
    };

    let module;
    jest.isolateModules(() => {
        module = require('Resources/extension/sw-sales-channel/agentic-commerce/settings-group');
    });

    return module;
}

function throwingStore() {
    return {
        get(id) {
            throw new Error(`Store with id "${id}" not found`);
        },
    };
}

function storeWithGroups(groups) {
    return { get: () => ({ settingsGroups: groups }) };
}

describe('settings-group', () => {
    afterEach(() => {
        delete globalThis.Shopware.Store;
        delete globalThis.Shopware.State;
    });

    it('files the item under commerce when that group exists', () => {
        const { settingsGroup } = loadSettingsGroup({
            store: storeWithGroups({ general: [], commerce: [], system: [] }),
        });

        expect(settingsGroup()).toBe('commerce');
    });

    it('falls back to shop on a lane whose groups predate commerce', () => {
        const { settingsGroup } = loadSettingsGroup({
            store: storeWithGroups({ general: [], shop: [], system: [] }),
        });

        expect(settingsGroup()).toBe('shop');
    });

    it('reads the Vuex registry when the Pinia one throws for the id', () => {
        const { settingsGroup } = loadSettingsGroup({
            store: throwingStore(),
            state: storeWithGroups({ commerce: [] }),
        });

        expect(settingsGroup()).toBe('commerce');
    });

    it('falls back to the lane version when no store is readable yet', () => {
        // Module registration can run before the settings store exists.
        expect(loadSettingsGroup({ store: throwingStore(), version: '6.7.0.0' }).settingsGroup()).toBe('commerce');
        expect(loadSettingsGroup({ store: throwingStore(), version: '6.5.8.0' }).settingsGroup()).toBe('shop');
    });
});