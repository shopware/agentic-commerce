/**
 * @sw-package discovery
 */

global.Shopware = {
    Component: { override: jest.fn() },
};

const { swSalesChannelCreateOverride } = require('./index');

function buildContext({ isExportConfigValid }) {
    return {
        isLoading: true,
        validateAgenticCommerceExportConfig: jest.fn(() => isExportConfigValid),
        $super: jest.fn(() => Promise.resolve('saved')),
    };
}

describe('sw-sales-channel-create onSave', () => {
    it('saves the new channel when the export config is complete', async () => {
        const ctx = buildContext({ isExportConfigValid: true });

        const result = await swSalesChannelCreateOverride.methods.onSave.call(ctx);

        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(result).toBe('saved');
    });

    // Regression #309: refusing before $super keeps core's $super chain intact, so the next click is checked too.
    it('does not save the new channel while a required export setting is missing', () => {
        const ctx = buildContext({ isExportConfigValid: false });

        swSalesChannelCreateOverride.methods.onSave.call(ctx);

        expect(ctx.$super).not.toHaveBeenCalled();
        expect(ctx.isLoading).toBe(false);
    });
});
