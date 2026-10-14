/**
 * @sw-package discovery
 *
 * Core ships `Defaults.agenticCommerceTypeId` from 6.7.10; older cores rely on the plugin to set it.
 * The repeated-init test resets the module registry, otherwise Jest returns the cached module and
 * the initializer never runs a second time.
 */

const PLUGIN_TYPE_ID = '5e29f9890c4d4d519a1c7f9d5c24b7c1';

describe('init/defaults.init', () => {
    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    it('sets the Agentic Commerce type id when core does not provide it', () => {
        global.Shopware = { Defaults: {} };

        require('./defaults.init');

        expect(global.Shopware.Defaults.agenticCommerceTypeId).toBe(PLUGIN_TYPE_ID);
    });

    it('keeps the type id core already provides', () => {
        global.Shopware = { Defaults: { agenticCommerceTypeId: 'type-id-provided-by-core' } };

        require('./defaults.init');

        expect(global.Shopware.Defaults.agenticCommerceTypeId).toBe('type-id-provided-by-core');
    });

    it('does not write the type id again when the initializer runs a second time', () => {
        let typeId;
        let reads = 0;
        const writes = [];
        const Defaults = {};
        Object.defineProperty(Defaults, 'agenticCommerceTypeId', {
            get() {
                reads += 1;
                return typeId;
            },
            set(value) {
                writes.push(value);
                typeId = value;
            },
        });
        global.Shopware = { Defaults };

        require('./defaults.init');
        jest.resetModules();
        require('./defaults.init');

        expect(reads).toBe(2);
        expect(writes).toEqual([PLUGIN_TYPE_ID]);
    });
});