/**
 * @sw-package discovery
 */

const fs = require('fs');
const path = require('path');

const template = fs.readFileSync(
    path.resolve(__dirname, './sw-sales-channel-detail.html.twig'),
    'utf8',
);
const socialShoppingTemplate = fs.readFileSync(
    path.resolve(__dirname, './fixtures/social-shopping-sales-channel-detail.fixture.twig'),
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

        const legacyRouterView = template.match(/<router-view\s+v-else([\s\S]*?)\/>/);
        expect(legacyRouterView).not.toBeNull();
        expect(legacyRouterView[1]).toContain(':sales-channel="salesChannel"');
        expect(legacyRouterView[1]).toContain(':agentic-commerce-export-config="agenticCommerceExportConfig"');
        expect(legacyRouterView[1]).toContain('@template-modal-confirm="onTemplateModalConfirm"');
    });

    it('preserves Social Shopping routed-view bindings through the parent template', () => {
        expect(template).toContain('{% parent %}');
        expect(socialShoppingTemplate).toContain(':is-social-shopping="isSocialShopping"');
        expect(socialShoppingTemplate).toContain(':social-shopping-type="socialShoppingType"');
        expect(socialShoppingTemplate).toContain(':is-new-entity="isNewEntity"');
    });
});
