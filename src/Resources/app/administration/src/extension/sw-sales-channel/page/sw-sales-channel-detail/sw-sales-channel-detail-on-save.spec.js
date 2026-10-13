/**
 * @sw-package discovery
 *
 * Exercises the cross-version save-flow branching in
 * `extension/sw-sales-channel/page/sw-sales-channel-detail/index.js`.
 *
 * Two code paths are unit-tested directly against the exported override
 * methods so we don't need a full component mount:
 *
 *   - 6.7.9.0+ (split-save): core exposes `saveSalesChannel()`. The plugin
 *     calls it, then runs the agentic export-config save, then triggers
 *     `loadEntityData()` itself.
 *   - 6.7.0.x–6.7.8.x (legacy onSave): core only has a monolithic `onSave()`
 *     that reloads the entity at the end (nulling `this.salesChannel`). The
 *     plugin must `$super('onSave')`, read `isSaveSuccessful` for the
 *     outcome, snapshot the channel id/config before calling super, and skip
 *     its own `loadEntityData()` call afterwards.
 */

const Shopware = {
    Component: { override: jest.fn() },
    Utils: {
        object: { deepCopyObject: (obj) => JSON.parse(JSON.stringify(obj ?? {})) },
    },
    Classes: {
        ShopwareError: class ShopwareError {
            constructor(payload) {
                this.payload = payload;
            }
        },
    },
    Context: { api: {} },
    Defaults: { agenticCommerceTypeId: 'agentic-type-id' },
};

global.Shopware = Shopware;

jest.mock(
    './sw-sales-channel-detail.html.twig',
    () => 'mock-template',
    { virtual: true },
);

const { swSalesChannelDetailOverride } = require('./index');

function buildContext({ saveSalesChannel, isSaveSuccessful = true } = {}) {
    const ctx = {
        salesChannel: { id: 'channel-id', typeId: 'agentic-type-id' },
        agenticCommerceExportConfig: [
            {
                provider: 'open-ai',
                systemConfigDomain: 'SwagAgenticCommerce.openAiProductExport',
                elements: [],
                values: { 'SwagAgenticCommerce.openAiProductExport.foo': 'bar' },
                errors: {},
                isLoading: false,
                isLoaded: true,
            },
        ],
        isSaveSuccessful,
        isLoading: false,
        isAgenticCommerce: true,
        systemConfigApiService: { batchSave: jest.fn(() => Promise.resolve()) },
        validateAgenticCommerceExportConfig: jest.fn(() => true),
        ucpState: { loaded: false, form: {}, savedForm: {}, generation: 0 },
        saveUcpState: jest.fn(() => Promise.resolve(true)),
        loadUcpState: jest.fn(),
        loadEntityData: jest.fn(),
        $super: jest.fn(),
        $t: jest.fn(),
        createNotificationError: jest.fn(),
        placeholder: jest.fn(),
    };

    if (saveSalesChannel !== undefined) {
        ctx.saveSalesChannel = saveSalesChannel;
    }

    // onSave delegates the agentic-config write to this method on the same
    // component; in real Vue both come from the same override, but in this
    // isolated unit context we mock it to keep the focus on onSave itself.
    ctx.saveAgenticCommerceExportConfig = jest.fn(() => Promise.resolve(true));

    return ctx;
}

describe('sw-sales-channel-detail onSave — 6.7.9.0+ split-save path', () => {
    it('calls saveSalesChannel() and then the agentic export config save', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveSalesChannel).toHaveBeenCalledTimes(1);
        expect(ctx.$super).not.toHaveBeenCalled();
        expect(ctx.saveAgenticCommerceExportConfig).toHaveBeenCalledTimes(1);
    });

    it('triggers loadEntityData() at the end because saveSalesChannel does not reload itself', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.loadEntityData).toHaveBeenCalledTimes(1);
    });

    it('skips the export config save when saveSalesChannel reports failure', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(false)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.loadEntityData).not.toHaveBeenCalled();
    });
});

describe('sw-sales-channel-detail onSave — 6.7.0–6.7.8.x legacy path', () => {
    it('falls back to $super(\'onSave\') and reads isSaveSuccessful', async () => {
        const ctx = buildContext({ isSaveSuccessful: true });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.saveAgenticCommerceExportConfig).toHaveBeenCalledTimes(1);
    });

    it('aborts when isSaveSuccessful is false after the $super call', async () => {
        const ctx = buildContext({ isSaveSuccessful: false });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
    });

    it('does not call loadEntityData() itself because the legacy onSave already did', async () => {
        const ctx = buildContext({ isSaveSuccessful: true });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.loadEntityData).not.toHaveBeenCalled();
    });

    it('passes the snapshotted channel id and config to saveAgenticCommerceExportConfig even when this.salesChannel was nulled by legacy reload', async () => {
        const ctx = buildContext({ isSaveSuccessful: true });
        const originalConfig = ctx.agenticCommerceExportConfig;
        ctx.$super = jest.fn(() => {
            /**
             * Emulate the legacy onSave nulling salesChannel and resetting the
             * agentic config array via loadEntityData() before we get control back.
             */
            ctx.salesChannel = null;
            ctx.agenticCommerceExportConfig = [];
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        // provider is snapshotted from this.productExport?.provider — undefined here
        // (no productExport in the mock), so saveAgenticCommerceExportConfig falls
        // back to the default provider.
        expect(ctx.saveAgenticCommerceExportConfig).toHaveBeenCalledWith('channel-id', originalConfig, undefined);
    });
});

// NOTE (6.5 backport): validation is non-blocking and runs post-save. There is
// no pre-save gate — the channel always saves first, then validation highlights
// any AC config errors without blocking the save.
describe('sw-sales-channel-detail onSave — post-save validation', () => {
    it('calls validateAgenticCommerceExportConfig after a successful channel save', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.validateAgenticCommerceExportConfig).toHaveBeenCalled();
    });
});

describe('sw-sales-channel-detail onSave — UCP config', () => {
    const UCP_FORM = { active: true, profileDomain: null, enabledCapabilities: ['cart', 'catalog'], enabledTransports: ['rest'] };

    it('writes the UCP form as it was when Save was clicked, although the legacy reload replaced it', async () => {
        const ctx = buildContext({ isSaveSuccessful: true });
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };
        ctx.$super = jest.fn(() => {
            ctx.ucpState.form = { active: false, profileDomain: null, enabledCapabilities: [], enabledTransports: ['rest'] };
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).toHaveBeenCalledWith('channel-id', UCP_FORM);
    });

    it('loads the stored UCP state again after a legacy save, which reloaded the page before the UCP write', async () => {
        const ctx = buildContext({ isSaveSuccessful: true });
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.loadUcpState).toHaveBeenCalledWith('channel-id', 'agentic-type-id');
    });

    it('leaves reloading to loadEntityData() on the split-save path', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.loadEntityData).toHaveBeenCalledTimes(1);
        expect(ctx.loadUcpState).not.toHaveBeenCalled();
    });

    it('does not write a UCP config that was never loaded', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).toHaveBeenCalledWith('channel-id', null);
    });

    it('saveUcpState stores the given form and supersedes loads started before the write', async () => {
        const saveConfig = jest.fn(() => Promise.resolve());
        const ctx = {
            ucpState: { loaded: true, form: { active: false }, savedForm: { active: false }, generation: 3 },
            ucpAdminApiService: { saveConfig },
            createNotificationError: jest.fn(),
        };

        const saved = await swSalesChannelDetailOverride.methods.saveUcpState.call(ctx, 'channel-id', UCP_FORM);

        expect(saved).toBe(true);
        expect(saveConfig).toHaveBeenCalledWith('channel-id', expect.objectContaining({ enabledCapabilities: ['cart', 'catalog'] }));
        expect(ctx.ucpState.form).toEqual(UCP_FORM);
        expect(ctx.ucpState.savedForm).toEqual(UCP_FORM);
        expect(ctx.ucpState.generation).toBe(4);
    });
});
