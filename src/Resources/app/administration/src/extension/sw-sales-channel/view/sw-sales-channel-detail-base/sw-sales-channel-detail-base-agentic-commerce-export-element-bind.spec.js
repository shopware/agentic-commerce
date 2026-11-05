/**
 * @sw-package discovery
 */

global.Shopware = {
    Component: { override: jest.fn() },
    Utils: { object: { deepCopyObject: (obj) => JSON.parse(JSON.stringify(obj ?? {})) } },
    Defaults: { agenticCommerceTypeId: 'agentic-type-id', productComparisonTypeId: 'product-comparison-type-id' },
    Context: { app: { config: { version: '' } } },
};

jest.mock(
    './sw-sales-channel-detail-base.html.twig',
    () => 'mock-template',
    { virtual: true },
);

const { swSalesChannelDetailBaseOverride } = require('./index');

const elementBind = swSalesChannelDetailBaseOverride.methods.getAgenticCommerceExportElementBind;
const returnPolicyUrlElement = { name: 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl', type: 'url', config: {} };

function givenShopwareVersion(version) {
    Shopware.Context.app.config.version = version;
}

describe('sw-sales-channel-detail-base getAgenticCommerceExportElementBind', () => {
    it('renders a url setting with the plugin url field while core does not report a cleared value', () => {
        givenShopwareVersion('6.5.8.19');

        expect(elementBind.call({}, returnPolicyUrlElement).config.componentName).toBe('sw-agentic-commerce-url-field');
    });

    it('leaves a url setting to core from 6.6.9.0, which reports a cleared value itself', () => {
        givenShopwareVersion('6.6.9.0');

        expect(elementBind.call({}, returnPolicyUrlElement).config.componentName).toBeUndefined();
    });

    it('keeps other settings on their own component', () => {
        givenShopwareVersion('6.5.8.19');

        expect(elementBind.call({}, { name: 'title', type: 'text', config: {} }).config.componentName).toBeUndefined();
    });
});
