const Shopware = {
    Component: { override: jest.fn(), getComponentRegistry: () => ({ has: () => true }) },
    Defaults: { agenticCommerceTypeId: 'agentic-commerce' },
};

global.Shopware = Shopware;

const { productExportInsightsOverride } = require('./index');

describe('core product export Insights ACL extension', () => {
    it('does not load statistics for an Agentic Commerce channel without ucp.viewer', async () => {
        const ctx = {
            salesChannel: { typeId: 'agentic-commerce' },
            isAgenticCommerceChannel: true,
            acl: { can: jest.fn(() => false) },
            isLoading: true,
            $super: jest.fn(),
        };

        await productExportInsightsOverride.methods.fetchData.call(ctx);

        expect(ctx.acl.can).toHaveBeenCalledWith('ucp.viewer');
        expect(ctx.$super).not.toHaveBeenCalled();
        expect(ctx.isLoading).toBe(false);
    });

    it('keeps core Insights loading for product-comparison channels', async () => {
        const ctx = {
            salesChannel: { typeId: 'product-comparison' },
            isAgenticCommerceChannel: false,
            acl: { can: jest.fn() },
            $super: jest.fn(() => Promise.resolve()),
        };

        await productExportInsightsOverride.methods.fetchData.call(ctx);

        expect(ctx.acl.can).not.toHaveBeenCalled();
        expect(ctx.$super).toHaveBeenCalledWith('fetchData');
    });

    it('keeps Agentic Commerce Insights available to ucp.viewer', async () => {
        const ctx = {
            salesChannel: { typeId: 'agentic-commerce' },
            isAgenticCommerceChannel: true,
            acl: { can: jest.fn(() => true) },
            $super: jest.fn(() => Promise.resolve()),
        };

        await productExportInsightsOverride.methods.fetchData.call(ctx);

        expect(ctx.acl.can).toHaveBeenCalledWith('ucp.viewer');
        expect(ctx.$super).toHaveBeenCalledWith('fetchData');
    });
});
