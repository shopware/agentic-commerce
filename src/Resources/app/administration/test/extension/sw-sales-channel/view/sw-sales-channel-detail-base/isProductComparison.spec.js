/**
 * @sw-package discovery
 *
 * The plugin widens `isProductComparison` so agentic-commerce sales channels
 * reuse the product-export code path that core gates on that flag.
 * The shim must:
 *   - return true for a regular product comparison channel
 *   - return true when the channel is agentic
 *   - return false when neither holds
 *
 * NOTE (6.5 backport): $super is not used here — it is unreliable in the 6.5
 * component lifecycle. The check is inlined against salesChannel.typeId.
 */

const Shopware = {
    Component: { override: jest.fn() },
    Utils: { object: { deepCopyObject: (obj) => JSON.parse(JSON.stringify(obj ?? {})) } },
    Defaults: { agenticCommerceTypeId: 'agentic-type-id', productComparisonTypeId: 'product-comparison-type-id' },
};

global.Shopware = Shopware;

jest.mock(
    '../../../../../src/extension/sw-sales-channel/view/sw-sales-channel-detail-base/sw-sales-channel-detail-base.html.twig',
    () => 'mock-template',
    { virtual: true },
);

// eslint-disable-next-line import/first
const { swSalesChannelDetailBaseOverride } = require('../../../../../src/extension/sw-sales-channel/view/sw-sales-channel-detail-base');

function callComputed(computed, ctx) {
    return computed.call(ctx);
}

describe('sw-sales-channel-detail-base — isProductComparison forward-compat shim', () => {
    const shim = swSalesChannelDetailBaseOverride.computed.isProductComparison;

    it('returns true for a regular product comparison channel', () => {
        const ctx = {
            isAgenticCommerce: false,
            salesChannel: { typeId: 'product-comparison-type-id' },
        };

        expect(callComputed(shim, ctx)).toBe(true);
    });

    it('widens to true for agentic channels', () => {
        const ctx = {
            isAgenticCommerce: true,
            salesChannel: null,
        };

        expect(callComputed(shim, ctx)).toBe(true);
    });

    it('returns false when neither a product comparison nor an agentic channel', () => {
        const ctx = {
            isAgenticCommerce: false,
            salesChannel: { typeId: 'storefront-type-id' },
        };

        expect(callComputed(shim, ctx)).toBe(false);
    });
});

describe('sw-sales-channel-detail-base — isAgenticCommerce computed', () => {
    const isAgenticCommerce = swSalesChannelDetailBaseOverride.computed.isAgenticCommerce;

    it('is true when salesChannel.typeId matches the agentic-commerce type id', () => {
        const ctx = { salesChannel: { typeId: 'agentic-type-id' } };
        expect(callComputed(isAgenticCommerce, ctx)).toBe(true);
    });

    it('is false when the channel has a different type', () => {
        const ctx = { salesChannel: { typeId: 'storefront-type' } };
        expect(callComputed(isAgenticCommerce, ctx)).toBe(false);
    });

    it('is falsy when there is no sales channel yet', () => {
        const ctx = { salesChannel: null };
        expect(callComputed(isAgenticCommerce, ctx)).toBeFalsy();
    });
});
