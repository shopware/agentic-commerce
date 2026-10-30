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
    it('keeps core routed-view props while hiding protected direct routes', () => {
        expect(template).toContain('{% block sw_sales_channel_detail_content_view %}');
        expect(template).toContain('v-if="shouldRenderAgenticCommerceContent"');
        expect(template).toContain('{% parent %}');
        expect(template).toContain(':sales-channel="salesChannel"');
        expect(template).toContain(':product-export="productExport"');
        expect(template).toContain(':agentic-commerce-export-config="agenticCommerceExportConfig"');
        expect(template).toContain(':storefront-sales-channel-criteria="storefrontSalesChannelCriteria"');
        expect(template).toContain(':custom-field-sets="customFieldSets"');
        expect(template).toContain(':template-options="productComparison.templateOptions"');
        expect(template).toContain('@template-modal-confirm="onTemplateModalConfirm"');
    });

    it('uses Vue Router 3 attribute forwarding on Shopware 6.5 and the scoped slot on 6.6+', () => {
        expect(template).toContain('<template v-if="supportsVueRouterViewSlot">');
        expect(template).toContain('v-slot="{ Component }"');

        const scopedComponent = template.match(/<component\b([\s\S]*?)\/>/);
        expect(scopedComponent).not.toBeNull();
        expect(scopedComponent[1]).toContain(':sales-channel="salesChannel"');
        expect(scopedComponent[1]).toContain(':product-export="productExport"');
        expect(scopedComponent[1]).toContain(':agentic-commerce-export-config="agenticCommerceExportConfig"');
        expect(scopedComponent[1]).toContain(':storefront-sales-channel-criteria="storefrontSalesChannelCriteria"');
        expect(scopedComponent[1]).toContain(':custom-field-sets="customFieldSets"');
        expect(scopedComponent[1]).toContain(':is-loading="isLoading"');
        expect(scopedComponent[1]).toContain(':product-comparison-access-url="productComparison.productComparisonAccessUrl"');
        expect(scopedComponent[1]).toContain(':template-options="productComparison.templateOptions"');
        expect(scopedComponent[1]).toContain(':show-template-modal="productComparison.showTemplateModal"');
        expect(scopedComponent[1]).toContain(':template-name="productComparison.templateName"');
        expect(scopedComponent[1]).toContain('@template-selected="onTemplateSelected"');
        expect(scopedComponent[1]).toContain('@access-key-changed="generateAccessUrl"');
        expect(scopedComponent[1]).toContain('@domain-changed="generateAccessUrl"');
        expect(scopedComponent[1]).toContain('@invalid-file-name="setInvalidFileName(true)"');
        expect(scopedComponent[1]).toContain('@valid-file-name="setInvalidFileName(false)"');
        expect(scopedComponent[1]).toContain('@template-modal-close="onTemplateModalClose"');
        expect(scopedComponent[1]).toContain('@template-modal-confirm="onTemplateModalConfirm"');

        const legacyRouterView = template.match(/<router-view\s+v-else([\s\S]*?)\/>/);
        expect(legacyRouterView).not.toBeNull();
        expect(legacyRouterView[1]).toContain(':sales-channel="salesChannel"');
        expect(legacyRouterView[1]).toContain(':agentic-commerce-export-config="agenticCommerceExportConfig"');
        expect(legacyRouterView[1]).toContain('@template-modal-confirm="onTemplateModalConfirm"');
    });

    it.each([
        ['6.5.8.18', false],
        ['6.6.0.0', true],
    ])('selects the router-view mode for Shopware %s', (version, expected) => {
        Shopware.Context.app = { config: { version } };

        expect(swSalesChannelDetailOverride.computed.supportsVueRouterViewSlot()).toBe(expected);
    });

    it('shows a localized warning when the routed content gate denies access', () => {
        expect(template).toContain('<template v-if="shouldRenderAgenticCommerceContent">');
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
