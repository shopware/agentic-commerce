/**
 * @sw-package discovery
 *
 * Verifies the probe that detects whether core already ships the Agentic Commerce admin.
 */

const CORE_COMPONENT = 'sw-sales-channel-detail-agentic-commerce-integration';

describe('core-feature: coreShipsAgenticCommerce', () => {
    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    function withRegistry(has) {
        global.Shopware = { Component: { getComponentRegistry: () => ({ has: () => has }) } };
        // eslint-disable-next-line global-require
        return require('../src/core-feature').coreShipsAgenticCommerce;
    }

    it('is true when core already registered the agentic integration component', () => {
        expect(withRegistry(true)).toBe(true);
    });

    it('is false when core has not registered it', () => {
        expect(withRegistry(false)).toBe(false);
    });

    it('probes the component registry for the core agentic integration component', () => {
        const has = jest.fn().mockReturnValue(true);
        global.Shopware = { Component: { getComponentRegistry: () => ({ has }) } };

        // eslint-disable-next-line global-require
        require('../src/core-feature');

        expect(has).toHaveBeenCalledWith(CORE_COMPONENT);
    });

    it('is false when the component API is unavailable', () => {
        global.Shopware = {};

        // eslint-disable-next-line global-require
        expect(require('../src/core-feature').coreShipsAgenticCommerce).toBe(false);
    });
});
