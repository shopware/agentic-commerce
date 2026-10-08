/**
 * @sw-package discovery
 *
 * Decides whether the Agentic Commerce tab shows without asking the backend. A type the client
 * cannot classify must fall through to the backend's resolver (`loadUcpState`) rather than being
 * hidden, so a core without one of these Defaults never hides the tab for every channel.
 */

const STOREFRONT = '8a243080f92e4c719546314b577cf82b';
const API = 'f183ee5650cf4bdb8a774337575067a6';
const PRODUCT_COMPARISON = 'ed535e5722134ac1aa6524f73e26881b';
const AGENTIC_COMMERCE = '5e29f9890c4d4d519a1c7f9d5c24b7c1';
const THIRD_PARTY = '0190a6b2c8f37b4e9d1c2a3b4c5d6e7f';

const CORE_DEFAULTS = {
    storefrontSalesChannelTypeId: STOREFRONT,
    apiSalesChannelTypeId: API,
    productComparisonTypeId: PRODUCT_COMPARISON,
    agenticCommerceTypeId: AGENTIC_COMMERCE,
};

describe('agentic-commerce/sales-channel-type.util', () => {
    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    function load(defaults) {
        global.Shopware = { Defaults: defaults };

        return require('./sales-channel-type.util');
    }

    it.each([
        ['storefront', STOREFRONT],
        ['headless API', API],
    ])('shows the tab for %s channels without asking the backend', (_, typeId) => {
        const { isTransactionalSalesChannelType, isKnownSalesChannelType } = load(CORE_DEFAULTS);

        expect(isTransactionalSalesChannelType(typeId)).toBe(true);
        expect(isKnownSalesChannelType(typeId)).toBe(true);
    });

    it.each([
        ['product comparison', PRODUCT_COMPARISON],
        ['Agentic Commerce', AGENTIC_COMMERCE],
    ])('hides the tab for %s channels without asking the backend', (_, typeId) => {
        const { isTransactionalSalesChannelType, isKnownSalesChannelType } = load(CORE_DEFAULTS);

        expect(isTransactionalSalesChannelType(typeId)).toBe(false);
        expect(isKnownSalesChannelType(typeId)).toBe(true);
    });

    it('leaves third-party channel types to the backend', () => {
        const { isTransactionalSalesChannelType, isKnownSalesChannelType } = load(CORE_DEFAULTS);

        expect(isTransactionalSalesChannelType(THIRD_PARTY)).toBe(false);
        expect(isKnownSalesChannelType(THIRD_PARTY)).toBe(false);
    });

    it('leaves storefront and API channels to the backend when core lacks their Defaults', () => {
        const { isTransactionalSalesChannelType, isKnownSalesChannelType } = load({
            productComparisonTypeId: PRODUCT_COMPARISON,
            agenticCommerceTypeId: AGENTIC_COMMERCE,
        });

        [STOREFRONT, API].forEach((typeId) => {
            expect(isTransactionalSalesChannelType(typeId)).toBe(false);
            expect(isKnownSalesChannelType(typeId)).toBe(false);
        });
    });
});