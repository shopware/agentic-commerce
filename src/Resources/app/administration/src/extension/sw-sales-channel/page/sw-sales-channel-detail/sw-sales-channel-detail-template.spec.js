/**
 * @sw-package discovery
 */

const fs = require('fs');
const path = require('path');

global.Shopware = {
    Component: { override: jest.fn() },
    Service: jest.fn(() => ({})),
    Utils: { object: {} },
    Classes: { ShopwareError: class ShopwareError extends Error {} },
    Context: { api: {} },
    Defaults: {
        productComparisonTypeId: 'product-comparison-type-id',
        storefrontSalesChannelTypeId: 'storefront-type-id',
        apiSalesChannelTypeId: 'api-type-id',
        agenticCommerceTypeId: 'agentic-commerce-type-id',
    },
};

const { swSalesChannelDetailOverride } = require('Resources/extension/sw-sales-channel/page/sw-sales-channel-detail');

const template = fs.readFileSync(
    path.resolve(__dirname, './sw-sales-channel-detail.html.twig'),
    'utf8',
);

describe('sw-sales-channel-detail template override', () => {
    it('wraps the core routed view without copying its props and listeners', () => {
        expect(template).toContain('{% block sw_sales_channel_detail_content_view %}');
        expect(template).toContain('<template v-if="!isAgenticCommerce || shouldRenderAgenticCommerceContent">');
        expect(template).toContain('{% parent %}');
        expect(template).not.toContain(':sales-channel="salesChannel"');
        expect(template).not.toContain('@template-modal-confirm="onTemplateModalConfirm"');
    });

    it('shows a localized warning when the routed content gate denies access', () => {
        expect(template).toContain('<template v-if="!isAgenticCommerce || shouldRenderAgenticCommerceContent">');
        expect(template).toContain('class="sw-sales-channel-detail__content-denied"');
        expect(template).toContain('<mt-banner v-if="usesMeteorComponents" variant="attention">');
        expect(template).toContain('<sw-alert v-else variant="info">');
        expect(template).toContain("{{ $t('sw-sales-channel.detail.contentDenied') }}");

        const shouldRenderContent = swSalesChannelDetailOverride.computed.shouldRenderAgenticCommerceContent;
        const insightsRoute = 'sw.sales.channel.detail.productExportInsights';
        const configRoute = 'sw.sales.channel.detail.agenticCommerce';

        expect(shouldRenderContent.call({
            isAgenticCommerce: true,
            $route: { name: insightsRoute },
            canViewUcpInsights: false,
            shouldRenderAgenticCommerceTab: true,
        })).toBe(false);
        expect(shouldRenderContent.call({
            isAgenticCommerce: true,
            $route: { name: insightsRoute },
            canViewUcpInsights: true,
            shouldRenderAgenticCommerceTab: true,
        })).toBe(true);
        expect(shouldRenderContent.call({
            isAgenticCommerce: true,
            $route: { name: configRoute },
            canViewUcpInsights: true,
            shouldRenderAgenticCommerceTab: false,
        })).toBe(false);
        expect(shouldRenderContent.call({
            isAgenticCommerce: true,
            $route: { name: configRoute },
            canViewUcpInsights: true,
            shouldRenderAgenticCommerceTab: true,
        })).toBe(true);
    });
});

describe('denied-content notification compatibility', () => {
    afterEach(() => {
        delete Shopware.Context.app;
    });

    it.each([
        ['6.5.8.18', false],
        ['6.6.1.0', false],
        ['6.6.2.0', true],
        ['6.7.9999999.9999999-dev', true],
    ])('uses the available notification component on %s', (version, expected) => {
        Shopware.Context.app = { config: { version } };

        expect(swSalesChannelDetailOverride.computed.usesMeteorComponents()).toBe(expected);
    });
});
