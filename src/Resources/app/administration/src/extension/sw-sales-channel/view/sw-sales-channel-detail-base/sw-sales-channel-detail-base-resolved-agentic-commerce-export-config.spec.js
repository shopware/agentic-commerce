/**
 * @sw-package discovery
 */

const Shopware = {
    Component: { override: jest.fn() },
    Utils: { object: { deepCopyObject: (obj) => JSON.parse(JSON.stringify(obj ?? {})) } },
    Defaults: { agenticCommerceTypeId: 'agentic-type-id', productComparisonTypeId: 'product-comparison-type-id' },
};

global.Shopware = Shopware;

jest.mock(
    './sw-sales-channel-detail-base.html.twig',
    () => 'mock-template',
    { virtual: true },
);

const { swSalesChannelDetailBaseOverride } = require('./index');

const resolvedAgenticCommerceExportConfig = swSalesChannelDetailBaseOverride.computed.resolvedAgenticCommerceExportConfig;

const googleEntry = { provider: 'google', templateName: 'google' };
const openAiEntry = { provider: 'openai', templateName: 'openai' };

describe('sw-sales-channel-detail-base — resolvedAgenticCommerceExportConfig', () => {
    it('reads the page config through inject when no prop is passed', () => {
        const ctx = {
            agenticCommerceExportConfig: [],
            swSalesChannelDetailGetAgenticCommerceExportConfig: () => [googleEntry, openAiEntry],
            productExport: { provider: 'openai' },
        };

        expect(resolvedAgenticCommerceExportConfig.call(ctx)).toEqual([openAiEntry]);
    });

    it('prefers the prop over the injected config', () => {
        const ctx = {
            agenticCommerceExportConfig: [googleEntry],
            swSalesChannelDetailGetAgenticCommerceExportConfig: () => [openAiEntry],
            productExport: { provider: 'google' },
        };

        expect(resolvedAgenticCommerceExportConfig.call(ctx)).toEqual([googleEntry]);
    });

    it('is empty when neither the prop nor the page provides a config', () => {
        const ctx = {
            agenticCommerceExportConfig: [],
            swSalesChannelDetailGetAgenticCommerceExportConfig: () => [],
            productExport: null,
        };

        expect(resolvedAgenticCommerceExportConfig.call(ctx)).toEqual([]);
    });
});
