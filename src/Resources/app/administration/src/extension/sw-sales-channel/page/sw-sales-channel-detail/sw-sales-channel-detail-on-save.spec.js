/**
 * @sw-package discovery
 *
 * Exercises the cross-version save-flow branching in
 * `extension/sw-sales-channel/page/sw-sales-channel-detail/index.js`.
 *
 * Two code paths are unit-tested directly against the exported override
 * methods so we don't need a full component mount:
 *
 *   - 6.7.9.0+ (parent save): the plugin delegates to core `onSave()` so required
 *     field validation, Storefront theme assignment and the reload stay in core.
 *   - 6.7.0.x–6.7.8.x (legacy onSave): core only has a monolithic `onSave()`
 *     that reloads the entity at the end (nulling `this.salesChannel`). The
 *     plugin must `$super('onSave')`, read `isSaveSuccessful` for the
 *     outcome, snapshot the channel id/config before calling super, and skip
 *     its own `loadEntityData()` call afterwards.
 */

const Shopware = {
    Component: {
        override: jest.fn(),
        getComponentRegistry: jest.fn(() => ({ has: () => false })),
    },
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
    Context: { api: {}, app: { config: { version: '6.7.10.0' } } },
    Defaults: { agenticCommerceTypeId: 'agentic-type-id' },
};

global.Shopware = Shopware;

afterEach(() => {
    Shopware.Context.app.config.version = '6.7.10.0';
});

jest.mock(
    './sw-sales-channel-detail.html.twig',
    () => 'mock-template',
    { virtual: true },
);

const { swSalesChannelDetailOverride } = require('./index');

function createFileEntity(id, data = {}, isNew = false) {
    const draft = { ...data };
    const origin = { ...data };
    const entity = {
        id,
        _isNew: isNew,
        isNew() {
            return this._isNew;
        },
        getOrigin: () => origin,
        getDraft: () => draft,
        getIsDirty: () => JSON.stringify(origin) !== JSON.stringify(draft),
    };

    return new Proxy(entity, {
        get(target, property) {
            return property in draft ? draft[property] : target[property];
        },
        set(target, property, value) {
            if (property in draft) {
                draft[property] = value;
            } else {
                target[property] = value;
            }

            return true;
        },
    });
}

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
        allowSaving: true,
        acl: { can: jest.fn(() => true) },
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

    // Keep the parent save outcome under test control.
    ctx.saveAgenticCommerceExportConfig = jest.fn(() => Promise.resolve(true));
    ctx.$super = jest.fn(async () => {
        ctx.isSaveSuccessful = isSaveSuccessful;
    });
    ctx.refreshUcpPreview = swSalesChannelDetailOverride.methods.refreshUcpPreview.bind(ctx);
    ctx.defaultAgenticCommerceExportConfig = [{ provider: 'open-ai' }];
    ctx.realSaveAgenticCommerceExportConfig = swSalesChannelDetailOverride.methods.saveAgenticCommerceExportConfig.bind(ctx);
    ctx.realValidateAgenticCommerceExportConfig = swSalesChannelDetailOverride.methods.validateAgenticCommerceExportConfig.bind(ctx);
    ctx.saveAgenticCommerceExportConfig = jest.fn((...args) => ctx.realSaveAgenticCommerceExportConfig(...args));
    ctx.validateAgenticCommerceExportConfig = jest.fn(() => ctx.realValidateAgenticCommerceExportConfig());

    return ctx;
}

function buildUcpContext() {
    const ctx = buildContext();
    ctx.isAgenticCommerce = false;
    ctx.$route = { name: 'sw.sales.channel.detail.agenticCommerce', params: { id: 'channel-id' } };
    ctx.$super = jest.fn(async () => {
        ctx.isSaveSuccessful = true;
    });
    return ctx;
}

describe('sw-sales-channel-detail onSave — 6.7.9.0+ parent save path', () => {
    it('delegates modern channel saves to the parent', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).toHaveBeenCalledWith('onSave');
    });

    it('leaves the background reload to the parent save', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.loadEntityData).not.toHaveBeenCalled();
    });

    it('validates required settings on the modern save path', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.validateAgenticCommerceExportConfig).toHaveBeenCalled();
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
    });

    it('does not write UCP settings when the parent save fails', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(), isSaveSuccessful: false });
        ctx.ucpState = { loaded: true, form: { active: true }, savedForm: { active: false }, generation: 0 };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.loadEntityData).not.toHaveBeenCalled();
    });
});

describe('feed settings compatibility by Administration version', () => {
    it.each(['6.7.9.0', '6.7.9.8', '6.6.10.0', '', 'unknown'])('saves valid feed settings on %s', async (version) => {
        Shopware.Context.app.config.version = version;
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.agenticCommerceExportConfig[0].elements = [{ name: 'returnPolicyUrl', config: { required: true } }];
        ctx.agenticCommerceExportConfig[0].values = { returnPolicyUrl: 'https://shop.example/updated-returns' };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.systemConfigApiService.batchSave).toHaveBeenCalledWith({
            'channel-id': { returnPolicyUrl: 'https://shop.example/updated-returns' },
        });
    });

    it.each(['6.7.9.0', '6.7.9.8', '6.6.10.0', '', 'unknown'])('rejects an empty required feed setting on %s', async (version) => {
        Shopware.Context.app.config.version = version;
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.agenticCommerceExportConfig[0].elements = [{ name: 'returnPolicyUrl', config: { required: true } }];
        ctx.agenticCommerceExportConfig[0].values = { returnPolicyUrl: '' };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).not.toHaveBeenCalled();
        expect(ctx.systemConfigApiService.batchSave).not.toHaveBeenCalled();
        expect(ctx.agenticCommerceExportConfig[0].errors.returnPolicyUrl).toBeDefined();
    });

    it('validates required settings before core save from 6.7.10', async () => {
        Shopware.Context.app.config.version = '6.7.10.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.validateAgenticCommerceExportConfig).toHaveBeenCalled();
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
    });

    it('stops after the channel save fails', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(), isSaveSuccessful: false });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.saveUcpState).not.toHaveBeenCalled();
    });

    it('does not reuse a previous success when core returns early without changing the save state', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)), isSaveSuccessful: true });
        ctx.$super = jest.fn(async () => {});

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.isSaveSuccessful).toBe(false);
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.saveUcpState).not.toHaveBeenCalled();
    });

    it('blocks the channel save when the required return policy URL is empty', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.agenticCommerceExportConfig[0].elements = [{ name: 'returnPolicyUrl', config: { required: true } }];
        ctx.agenticCommerceExportConfig[0].values = { returnPolicyUrl: '' };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveSalesChannel).not.toHaveBeenCalled();
        expect(ctx.$super).not.toHaveBeenCalled();
        expect(ctx.agenticCommerceExportConfig[0].errors.returnPolicyUrl).toBeDefined();
    });

    it('persists a changed return policy URL for the edited channel', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.agenticCommerceExportConfig[0].elements = [{ name: 'returnPolicyUrl', config: { required: true } }];
        ctx.agenticCommerceExportConfig[0].values = { returnPolicyUrl: 'https://shop.example/updated-returns' };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.systemConfigApiService.batchSave).toHaveBeenCalledWith({
            'channel-id': {
                returnPolicyUrl: 'https://shop.example/updated-returns',
            },
        });
    });

    it('keeps saved feed settings when the old read finishes before the write', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        const key = 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl';
        const updatedUrl = 'https://shop.example/updated-returns';
        const oldUrl = 'https://shop.example/old-returns';
        ctx.defaultAgenticCommerceExportConfig = [{
            provider: 'open-ai',
            systemConfigDomain: 'SwagAgenticCommerce.openAiProductExport',
        }];
        ctx.agenticCommerceExportConfig[0].elements = [{ name: key, config: { required: true } }];
        ctx.agenticCommerceExportConfig[0].values = { [key]: updatedUrl };

        let finishRead;
        let finishWrite;
        const oldRead = new Promise((resolve) => { finishRead = resolve; });
        const write = new Promise((resolve) => { finishWrite = resolve; });
        ctx.systemConfigApiService.getConfig = jest.fn(async () => [{ elements: [{ name: key, config: { required: true } }] }]);
        ctx.systemConfigApiService.getValues = jest.fn(() => oldRead);
        ctx.systemConfigApiService.batchSave.mockImplementationOnce(() => write);
        ctx.loadAgenticCommerceExportConfig = swSalesChannelDetailOverride.methods.loadAgenticCommerceExportConfig.bind(ctx);
        let reload;
        ctx.$super = jest.fn(async () => {
            reload = ctx.loadAgenticCommerceExportConfig();
            ctx.isSaveSuccessful = true;
        });

        const saving = swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await Promise.resolve();
        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        expect(ctx.$super).toHaveBeenCalledTimes(1);
        expect(ctx.systemConfigApiService.batchSave).toHaveBeenCalledWith({ 'channel-id': { [key]: updatedUrl } });

        finishRead({ [key]: oldUrl });
        await reload;
        finishWrite();
        await saving;
        expect(ctx.agenticCommerceExportConfig[0].values[key]).toBe(updatedUrl);

        ctx.$super.mockImplementation(async () => { ctx.isSaveSuccessful = true; });
        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        expect(ctx.systemConfigApiService.batchSave).toHaveBeenLastCalledWith({ 'channel-id': { [key]: updatedUrl } });
    });

    it('keeps saved feed settings when the write finishes before the old read', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        const key = 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl';
        const updatedUrl = 'https://shop.example/updated-returns';
        const oldUrl = 'https://shop.example/old-returns';
        ctx.defaultAgenticCommerceExportConfig = [{
            provider: 'open-ai',
            systemConfigDomain: 'SwagAgenticCommerce.openAiProductExport',
        }];
        ctx.agenticCommerceExportConfig[0].elements = [{ name: key, config: { required: true } }];
        ctx.agenticCommerceExportConfig[0].values = { [key]: updatedUrl };

        let finishRead;
        let finishWrite;
        const oldRead = new Promise((resolve) => { finishRead = resolve; });
        const write = new Promise((resolve) => { finishWrite = resolve; });
        ctx.systemConfigApiService.getConfig = jest.fn(async () => [{ elements: [{ name: key, config: { required: true } }] }]);
        ctx.systemConfigApiService.getValues = jest.fn(() => oldRead);
        ctx.systemConfigApiService.batchSave.mockImplementationOnce(() => write);
        ctx.loadAgenticCommerceExportConfig = swSalesChannelDetailOverride.methods.loadAgenticCommerceExportConfig.bind(ctx);
        let reload;
        ctx.$super = jest.fn(async () => {
            reload = ctx.loadAgenticCommerceExportConfig();
            ctx.isSaveSuccessful = true;
        });

        const saving = swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await Promise.resolve();
        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        expect(ctx.$super).toHaveBeenCalledTimes(1);
        expect(ctx.systemConfigApiService.batchSave).toHaveBeenCalledWith({ 'channel-id': { [key]: updatedUrl } });

        finishWrite();
        await saving;
        finishRead({ [key]: oldUrl });
        await reload;
        expect(ctx.agenticCommerceExportConfig[0].values[key]).toBe(updatedUrl);

        ctx.$super.mockImplementation(async () => { ctx.isSaveSuccessful = true; });
        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        expect(ctx.systemConfigApiService.batchSave).toHaveBeenLastCalledWith({ 'channel-id': { [key]: updatedUrl } });
    });

    it('stops UCP writes when the actual feed settings API write fails', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.systemConfigApiService.batchSave.mockRejectedValue(new Error('write failed'));
        ctx.ucpState = { loaded: true, form: { active: true }, savedForm: {}, generation: 0 };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.isSaveSuccessful).toBe(false);
    });

    it('stops UCP writes and clears success when feed persistence fails', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.saveAgenticCommerceExportConfig.mockResolvedValue(false);
        ctx.ucpState = { loaded: true, form: { active: true }, savedForm: {}, generation: 0 };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.isSaveSuccessful).toBe(false);
    });
});

describe('sw-sales-channel-detail onSave — 6.7.0–6.7.8.x legacy path', () => {
    it('validates provider fields before delegating to the legacy save', async () => {
        const ctx = buildContext();
        ctx.validateAgenticCommerceExportConfig.mockReturnValue(false);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.validateAgenticCommerceExportConfig).toHaveBeenCalledTimes(1);
        expect(ctx.$super).not.toHaveBeenCalled();
    });

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
            ctx.isSaveSuccessful = true;
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        // provider is snapshotted from this.productExport?.provider — undefined here
        // (no productExport in the mock), so saveAgenticCommerceExportConfig falls
        // back to the default provider.
        expect(ctx.saveAgenticCommerceExportConfig).toHaveBeenCalledWith('channel-id', originalConfig, undefined);
    });
});

describe('sw-sales-channel-detail onSave — export configuration validation', () => {
    it('validates export configuration before saving the channel', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.validateAgenticCommerceExportConfig.mock.invocationCallOrder[0])
            .toBeLessThan(ctx.$super.mock.invocationCallOrder[0]);
    });
});

describe('sw-sales-channel-detail onSave — refused save', () => {
    it('does not save while a required export setting is missing', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.validateAgenticCommerceExportConfig = jest.fn(() => false);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveSalesChannel).not.toHaveBeenCalled();
        expect(ctx.$super).not.toHaveBeenCalled();
    });

    it('tells the merchant which tab holds the missing setting, since Save also works from other tabs', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.salesChannel.isNew = () => false;
        ctx.validateAgenticCommerceExportConfig = jest.fn(() => false);
        ctx.$t = jest.fn((key) => key);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.createNotificationError).toHaveBeenCalledWith({
            message: 'sw-sales-channel.detail.agenticCommerce.messageRequiredSettingsMissingOnGeneralTab',
        });
    });

    it('names no tab while a new channel is created, as the create page has none', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.salesChannel.isNew = () => true;
        ctx.validateAgenticCommerceExportConfig = jest.fn(() => false);
        ctx.$t = jest.fn((key) => key);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.createNotificationError).toHaveBeenCalledWith({
            message: 'sw-sales-channel.detail.agenticCommerce.messageRequiredSettingsMissing',
        });
    });

    it('resets core\'s $super pointer so the next Save on the create page runs the check again', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.validateAgenticCommerceExportConfig = jest.fn(() => false);
        ctx._virtualCallStack = { onSave: 'sw-sales-channel-detail', createdComponent: 'sw-sales-channel-detail' };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx._virtualCallStack).toEqual({ onSave: undefined, createdComponent: 'sw-sales-channel-detail' });
    });
});

describe('sw-sales-channel-detail onSave — UCP config', () => {
    const UCP_FORM = { active: true, profileDomain: null, enabledCapabilities: ['cart', 'catalog'], enabledTransports: ['rest'] };

    it('allows UCP editors to save on the UCP route without sales_channel.editor', () => {
        expect(swSalesChannelDetailOverride.computed.allowSaving.call({
            isUcpConfigRoute: true,
            acl: { can: (privilege) => privilege === 'ucp.editor' },
        })).toBe(true);
    });

    it('allows sales-channel editors to save file changes on the UCP route', () => {
        expect(swSalesChannelDetailOverride.computed.allowSaving.call({
            isUcpConfigRoute: true,
            acl: { can: (privilege) => privilege === 'sales_channel.editor' },
        })).toBe(true);
    });

    it.each(['ucp.viewer', 'ucp.key_rotator'])('does not allow %s to save without sales-channel edit permission', (role) => {
        expect(swSalesChannelDetailOverride.computed.allowSaving.call({
            isUcpConfigRoute: true,
            acl: { can: (privilege) => privilege === role },
        })).toBe(false);
    });

    it('uses the core save permission on other routes', () => {
        const superMethod = jest.fn(() => false);

        expect(swSalesChannelDetailOverride.computed.allowSaving.call({ isUcpConfigRoute: false, $super: superMethod })).toBe(false);
        expect(superMethod).toHaveBeenCalledWith('allowSaving');
    });

    it('writes the UCP form as it was when Save was clicked, although the legacy reload replaced it', async () => {
        const ctx = buildContext({ isSaveSuccessful: true });
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };
        ctx.$super = jest.fn(() => {
            ctx.ucpState.form = { active: false, profileDomain: null, enabledCapabilities: [], enabledTransports: ['rest'] };
            ctx.isSaveSuccessful = true;
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

    it('leaves reloading to the parent on the modern save path', async () => {
        Shopware.Context.app.config.version = '6.7.10.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.loadUcpState).not.toHaveBeenCalled();
    });

    it('persists the original feed settings and channel after the core reload replaces live state', async () => {
        Shopware.Context.app.config.version = '6.7.9.0';
        const ctx = buildContext({ saveSalesChannel: jest.fn(() => Promise.resolve(true)) });
        ctx.productExport = { provider: 'open-ai' };
        ctx.$super = jest.fn(() => {
            ctx.salesChannel = { id: 'another-channel', typeId: 'other-type' };
            ctx.agenticCommerceExportConfig = [];
            ctx.productExport = { provider: 'other-provider' };
            ctx.isSaveSuccessful = true;
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.systemConfigApiService.batchSave).toHaveBeenCalledWith({
            'channel-id': { 'SwagAgenticCommerce.openAiProductExport.foo': 'bar' },
        });
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
            acl: { can: jest.fn(() => true) },
            createNotificationError: jest.fn(),
        };
        ctx.reloadAgenticFiles = swSalesChannelDetailOverride.methods.reloadAgenticFiles.bind(ctx);

        const saved = await swSalesChannelDetailOverride.methods.saveUcpState.call(ctx, 'channel-id', UCP_FORM);

        expect(saved).toBe(true);
        expect(saveConfig).toHaveBeenCalledWith('channel-id', expect.objectContaining({ enabledCapabilities: ['cart', 'catalog'] }));
        expect(ctx.ucpState.form).toEqual(UCP_FORM);
        expect(ctx.ucpState.savedForm).toEqual(UCP_FORM);
        expect(ctx.ucpState.generation).toBe(4);
    });

    it('keeps an activated UCP config saved when the Agentic Files reload fails, without retrying activation', async () => {
        const saveConfig = jest.fn(() => Promise.resolve());
        const reloadAgenticFiles = jest.fn(() => Promise.reject(new Error('reload failed')));
        const ctx = {
            ucpState: {
                loaded: true,
                form: { ...UCP_FORM },
                savedForm: { ...UCP_FORM, active: false },
                generation: 3,
            },
            ucpAdminApiService: { saveConfig },
            acl: { can: jest.fn(() => true) },
            createNotificationError: jest.fn(),
            reloadAgenticFiles,
        };

        await expect(swSalesChannelDetailOverride.methods.saveUcpState.call(ctx, 'channel-id', UCP_FORM)).resolves.toBe(true);

        expect(ctx.ucpState.savedForm).toEqual(UCP_FORM);
        expect(ctx.ucpState.generation).toBe(4);
        expect(reloadAgenticFiles).toHaveBeenCalledTimes(1);
        expect(ctx.createNotificationError).not.toHaveBeenCalled();

        await expect(swSalesChannelDetailOverride.methods.saveUcpState.call(ctx, 'channel-id', UCP_FORM)).resolves.toBe(true);

        expect(saveConfig).toHaveBeenCalledTimes(2);
        expect(reloadAgenticFiles).toHaveBeenCalledTimes(1);
    });

    it('preserves Agentic File drafts when reloading backend defaults after UCP activation', async () => {
        const fileData = {
            salesChannelId: 'channel-id',
            fileFamily: 'agentic',
            fileName: 'agents.md',
            enabled: true,
            templateOverrides: {},
        };
        const draft = createFileEntity('draft-file', fileData);
        draft.enabled = false;
        draft.templateOverrides = { content: 'Merchant instructions' };
        const savedFile = createFileEntity('backend-default-id', fileData);
        const ctx = buildUcpContext();
        ctx.salesChannel.salesChannelFiles = [draft];
        ctx.salesChannelRepository = {
            get: jest.fn(() => Promise.resolve({ salesChannelFiles: [savedFile] })),
        };
        ctx.getLoadSalesChannelCriteria = jest.fn(() => ({}));
        ctx.repositoryFactory = {
            create: jest.fn(() => ({ hasChanges: jest.fn(() => true) })),
        };
        Shopware.Component.getComponentRegistry.mockReturnValueOnce({ has: () => true });

        await swSalesChannelDetailOverride.methods.reloadAgenticFiles.call(ctx, 'channel-id');

        expect(ctx.salesChannelRepository.get).toHaveBeenCalledWith('channel-id', Shopware.Context.api, {});
        expect(ctx.salesChannel.salesChannelFiles[0].id).toBe('backend-default-id');
        expect(ctx.salesChannel.salesChannelFiles[0].enabled).toBe(false);
        expect(ctx.salesChannel.salesChannelFiles[0].templateOverrides).toEqual({
            content: 'Merchant instructions',
        });
    });

    it('keeps reconciled files when the parent save reload assigns an older sales-channel entity later', async () => {
        const reconciledFile = createFileEntity('backend-default-id', {
            fileFamily: 'agentic',
            fileName: 'agents.md',
            enabled: true,
        });
        const staleSalesChannel = { id: 'channel-id', salesChannelFiles: [] };
        const ctx = buildContext();
        ctx.isAgenticCommerce = false;
        ctx.$route = { name: 'sw.sales.channel.detail.base', params: { id: 'channel-id' } };
        ctx.ucpState = {
            loaded: true,
            form: { ...UCP_FORM },
            savedForm: { ...UCP_FORM, active: false },
            generation: 0,
        };
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);
        ctx.$super = jest.fn(async () => {
            ctx.salesChannel = null;
            ctx.isSaveSuccessful = true;
        });
        ctx.ucpAdminApiService = {
            saveConfig: jest.fn(() => Promise.resolve()),
            getProfilePreview: jest.fn(() => Promise.resolve({ data: { data: null } })),
        };
        ctx.salesChannelRepository = {
            get: jest.fn(() => Promise.resolve({ salesChannelFiles: [reconciledFile] })),
        };
        ctx.getLoadSalesChannelCriteria = jest.fn(() => ({}));
        ctx.reloadAgenticFiles = swSalesChannelDetailOverride.methods.reloadAgenticFiles.bind(ctx);
        Shopware.Component.getComponentRegistry.mockReturnValueOnce({ has: () => true });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.pendingAgenticFilesReconciliation).toEqual({
            salesChannelId: 'channel-id',
            salesChannelFiles: [reconciledFile],
        });

        ctx.salesChannel = staleSalesChannel;
        swSalesChannelDetailOverride.watch.salesChannel.call(ctx, staleSalesChannel);

        expect(staleSalesChannel.salesChannelFiles).toEqual([reconciledFile]);
        expect(ctx.pendingAgenticFilesReconciliation).toBeNull();
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it('saveUcpState keeps the draft when the write is refused, so a reload in flight cannot replace it', async () => {
        const storedForm = { active: false, profileDomain: null, enabledCapabilities: [], enabledTransports: ['rest'] };
        const saveConfig = jest.fn(() => Promise.reject(new Error('unsupported placeholder "{foo}"')));
        const ctx = {
            ucpState: { loaded: true, form: { ...storedForm }, savedForm: { ...storedForm }, generation: 3 },
            ucpAdminApiService: { saveConfig },
            acl: { can: jest.fn(() => true) },
            createNotificationError: jest.fn(),
        };

        const saved = await swSalesChannelDetailOverride.methods.saveUcpState.call(ctx, 'channel-id', UCP_FORM);

        expect(saved).toBe(false);
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
        expect(ctx.ucpState.form).toEqual(UCP_FORM);
        expect(ctx.ucpState.savedForm).toEqual(storedForm);
        expect(ctx.ucpState.generation).toBe(4);
    });

    it('lets a UCP viewer with sales-channel edit access save Agentic Files without writing UCP settings', async () => {
        const file = createFileEntity('new-file', { fileName: 'agents.md' }, true);
        const { repository: fileRepository } = createFileRepository([
            { id: 'new-file', data: { fileName: 'agents.md' } },
        ]);
        const ctx = buildUcpContext();
        ctx.acl.can.mockImplementation((privilege) => privilege === 'sales_channel.editor' || privilege === 'ucp.viewer');
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };
        ctx.salesChannel.salesChannelFiles = [file];
        ctx.repositoryFactory = { create: jest.fn(() => fileRepository) };
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn(() => Promise.resolve({ data: { data: null } })) };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(fileRepository.save).toHaveBeenCalledWith(file, Shopware.Context.api);
        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it('does not write UCP configuration from other tabs when the user lacks ucp.editor', async () => {
        const ctx = {
            ucpState: { loaded: true, form: { active: true }, savedForm: {}, generation: 0 },
            acl: { can: jest.fn(() => false) },
            ucpAdminApiService: { saveConfig: jest.fn() },
        };

        const saved = await swSalesChannelDetailOverride.methods.saveUcpState.call(ctx, 'channel-id', { active: true });

        expect(saved).toBe(true);
        expect(ctx.ucpAdminApiService.saveConfig).not.toHaveBeenCalled();
    });

    it('saves the sales channel from the UCP tab before writing UCP and refreshing the preview', async () => {
        const draft = { ...UCP_FORM };
        const preview = { data: { data: { ucp: { version: '1.0' } } } };
        const ctx = buildUcpContext();
        ctx.ucpState = { loaded: true, form: draft, savedForm: {}, generation: 0 };
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn(() => Promise.resolve(preview)) };
        ctx.saveUcpState = jest.fn(() => Promise.resolve(true));
        ctx.$super = jest.fn(async () => {
            ctx.isSaveSuccessful = true;
            ctx.isLoading = true;
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await Promise.resolve();

        expect(ctx.saveUcpState).toHaveBeenCalledWith('channel-id', draft);
        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.ucpAdminApiService.getProfilePreview).toHaveBeenCalledWith('channel-id');
        expect(ctx.ucpState.preview).toEqual(preview.data.data);
        expect(ctx.isSaveSuccessful).toBe(true);
        expect(ctx.isSavingUcp).toBe(false);
    });

    it('includes changed Agentic Files in the page-wide save from the UCP tab', async () => {
        const draft = { ...UCP_FORM };
        const newConfiguration = createFileEntity('new-file', {}, true);
        const changedConfiguration = createFileEntity('changed-file', { fileName: 'old' });
        changedConfiguration.fileName = 'new';
        const unchangedConfiguration = createFileEntity('unchanged-file', { fileName: 'same' });
        const { repository: fileRepository } = createFileRepository([
            { id: 'new-file', data: { fileName: 'new' } },
            { id: 'changed-file', data: { fileName: 'new' } },
            { id: 'unchanged-file', data: { fileName: 'same' } },
        ]);
        const ctx = buildUcpContext();
        ctx.ucpState = { loaded: true, form: draft, savedForm: {}, generation: 0 };
        ctx.salesChannel.salesChannelFiles = [newConfiguration, changedConfiguration, unchangedConfiguration];
        ctx.repositoryFactory = { create: jest.fn(() => fileRepository) };
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn(() => Promise.resolve({ data: { data: null } })) };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await Promise.resolve();

        expect(ctx.saveUcpState).toHaveBeenCalledWith('channel-id', draft);
        expect(ctx.repositoryFactory.create).toHaveBeenCalledWith('sales_channel_file');
        expect(fileRepository.hasChanges).toHaveBeenCalledWith(changedConfiguration);
        expect(fileRepository.hasChanges).toHaveBeenCalledWith(unchangedConfiguration);
        expect(fileRepository.save).toHaveBeenCalledTimes(2);
        expect(fileRepository.save).toHaveBeenCalledWith(newConfiguration, Shopware.Context.api);
        expect(fileRepository.save).toHaveBeenCalledWith(changedConfiguration, Shopware.Context.api);
        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it('saves Agentic File drafts before activating UCP so defaults cannot replace their state', async () => {
        const file = createFileEntity('draft-file', {
            fileName: 'agents.md',
            enabled: false,
            content: '# Customized instructions',
        }, true);
        const { repository } = createFileRepository();
        const ctx = buildUcpContext();
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };
        ctx.salesChannel.salesChannelFiles = [file];
        ctx.repositoryFactory = { create: jest.fn(() => repository) };
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn(() => Promise.resolve({ data: { data: null } })) };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(repository.save).toHaveBeenCalledWith(file, Shopware.Context.api);
        expect(repository.save.mock.invocationCallOrder[0]).toBeLessThan(ctx.saveUcpState.mock.invocationCallOrder[0]);
        expect(file.enabled).toBe(false);
        expect(file.content).toBe('# Customized instructions');
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it('does not save Agentic Files without sales-channel edit permission', async () => {
        const file = createFileEntity('draft-file', { fileName: 'agents.md' }, true);
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.acl.can.mockImplementation((privilege) => privilege === 'ucp.editor');
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };
        ctx.pendingAgenticFilesReload = 'channel-id';
        ctx.reloadAgenticFiles = jest.fn();
        ctx.salesChannel.salesChannelFiles = [file];
        ctx.saveAgenticFiles = jest.fn(() => Promise.resolve(true));
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn(() => Promise.resolve({ data: { data: null } })) };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).toHaveBeenCalledWith('channel-id', ctx.ucpState.form);
        expect(ctx.$super).not.toHaveBeenCalled();
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.saveAgenticFiles).not.toHaveBeenCalled();
        expect(ctx.reloadAgenticFiles).not.toHaveBeenCalled();
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it('skips unchanged UCP settings without saving the sales channel for a UCP-only editor', async () => {
        const ctx = buildUcpContext();
        ctx.isUcpConfigRoute = true;
        ctx.acl.can.mockImplementation((privilege) => privilege === 'ucp.editor');
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: { ...UCP_FORM }, generation: 0 };
        ctx.saveAgenticFiles = jest.fn();
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn() };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).not.toHaveBeenCalled();
        expect(ctx.saveAgenticFiles).not.toHaveBeenCalled();
        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.ucpAdminApiService.getProfilePreview).not.toHaveBeenCalled();
        expect(ctx.isSavingUcp).toBe(false);
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it('releases the UCP-only save guard after a failed write so the draft can be retried', async () => {
        const ctx = buildUcpContext();
        ctx.isUcpConfigRoute = true;
        ctx.acl.can.mockImplementation((privilege) => privilege === 'ucp.editor');
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };
        ctx.saveUcpState.mockResolvedValueOnce(false).mockResolvedValueOnce(true);
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn(async () => ({ data: { data: null } })) };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.isSavingUcp).toBe(false);
        expect(ctx.isLoading).toBe(false);
        expect(ctx.isSaveSuccessful).toBe(false);
        expect(ctx.ucpState.form).toEqual(UCP_FORM);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).not.toHaveBeenCalled();
        expect(ctx.saveUcpState).toHaveBeenCalledTimes(2);
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it('does not create the same Agentic File again on a repeated save', async () => {
        const { repository: fileRepository } = createFileRepository();
        const ctx = buildContext();
        ctx.salesChannel.salesChannelFiles = [createFileEntity('file-id', { fileName: 'agents.md' }, true)];
        ctx.repositoryFactory = { create: jest.fn(() => fileRepository) };

        await swSalesChannelDetailOverride.methods.saveAgenticFiles.call(ctx);
        await swSalesChannelDetailOverride.methods.saveAgenticFiles.call(ctx);

        expect(fileRepository.save).toHaveBeenCalledTimes(1);
        expect(fileRepository.get).toHaveBeenCalledTimes(1);
        expect(ctx.salesChannel.salesChannelFiles[0].isNew()).toBe(false);
    });

    it('keeps a successfully created file saved when its reload fails, so retry does not create it again', async () => {
        const file = createFileEntity('file-id', { fileName: 'agents.md' }, true);
        const { repository } = createFileRepository([
            { id: 'file-id', data: { fileName: 'agents.md' } },
        ]);
        repository.get.mockRejectedValueOnce(new Error('reload failed'));
        const ctx = buildContext();
        ctx.salesChannel.salesChannelFiles = [file];
        ctx.repositoryFactory = { create: jest.fn(() => repository) };

        await expect(swSalesChannelDetailOverride.methods.saveAgenticFiles.call(ctx)).resolves.toBe(false);
        expect(file.isNew()).toBe(false);

        await expect(swSalesChannelDetailOverride.methods.saveAgenticFiles.call(ctx)).resolves.toBe(true);
        expect(repository.save).toHaveBeenCalledTimes(1);
        expect(repository.get).toHaveBeenCalledTimes(1);
    });

    it('persists a reversed toggle after a successful write whose reload fails', async () => {
        const { repository, persistedFiles } = createFileRepository([
            { id: 'file-id', data: { enabled: false } },
        ]);
        repository.get.mockRejectedValueOnce(new Error('reload failed'));
        const ctx = buildContext();
        const file = createFileEntity('file-id', { enabled: false });
        ctx.salesChannel.salesChannelFiles = [file];
        ctx.repositoryFactory = { create: jest.fn(() => repository) };

        file.enabled = true;
        await expect(swSalesChannelDetailOverride.methods.saveAgenticFiles.call(ctx)).resolves.toBe(false);
        expect(persistedFiles.get('file-id')).toEqual({ enabled: true });

        file.enabled = false;
        await expect(swSalesChannelDetailOverride.methods.saveAgenticFiles.call(ctx)).resolves.toBe(true);

        expect(repository.save).toHaveBeenCalledTimes(2);
        expect(persistedFiles.get('file-id')).toEqual({ enabled: false });
    });

    it('persists a reversed Agentic File toggle after reloading the saved entity', async () => {
        const { repository: fileRepository, persistedFiles } = createFileRepository([
            { id: 'file-id', data: { enabled: false } },
        ]);
        const ctx = buildContext();
        ctx.salesChannel.salesChannelFiles = [createFileEntity('file-id', { enabled: false })];
        ctx.repositoryFactory = { create: jest.fn(() => fileRepository) };
        ctx.salesChannel.salesChannelFiles[0].enabled = true;

        await swSalesChannelDetailOverride.methods.saveAgenticFiles.call(ctx);
        ctx.salesChannel.salesChannelFiles[0].enabled = false;
        await swSalesChannelDetailOverride.methods.saveAgenticFiles.call(ctx);

        expect(fileRepository.save).toHaveBeenCalledTimes(2);
        expect(fileRepository.get).toHaveBeenCalledTimes(2);
        expect(persistedFiles.get('file-id')).toEqual({ enabled: false });
    });

    it('reports a failed Agentic Files write as an unsuccessful UCP-tab save', async () => {
        const ctx = buildUcpContext();
        ctx.ucpState = { loaded: true, form: { ...UCP_FORM }, savedForm: {}, generation: 0 };
        ctx.salesChannel.salesChannelFiles = [createFileEntity('new-file', {}, true)];
        ctx.repositoryFactory = {
            create: jest.fn(() => ({
                hasChanges: jest.fn(),
                save: jest.fn(() => Promise.reject(new Error('write failed'))),
                get: jest.fn(),
            })),
        };
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn() };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.createNotificationError).toHaveBeenCalledWith({
            message: ctx.$t('sw-sales-channel.detail.messageAPIError'),
        });
        expect(ctx.isSaveSuccessful).toBe(false);
        expect(ctx.ucpAdminApiService.getProfilePreview).not.toHaveBeenCalled();
    });

    it('does not save from the UCP tab when core disallows saving', async () => {
        const ctx = buildUcpContext();
        ctx.allowSaving = false;
        ctx.ucpState.loaded = true;

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.$super).not.toHaveBeenCalled();
    });

    it('saves the sales channel without writing UCP before its configuration has loaded', async () => {
        const ctx = buildUcpContext();
        ctx.ucpState.loaded = false;
        ctx.$super = jest.fn(async () => { ctx.isSaveSuccessful = true; });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.isLoading).toBe(false);
    });

    it('does not start another UCP save while the page is loading', async () => {
        const ctx = buildUcpContext();
        ctx.isLoading = true;
        ctx.ucpState.loaded = true;
        ctx.pendingAgenticFilesReload = 'channel-id';
        ctx.reloadAgenticFiles = jest.fn();

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.reloadAgenticFiles).not.toHaveBeenCalled();
        expect(ctx.isLoading).toBe(true);
    });

    it('keeps the draft after a failed UCP save without resetting the parent loading state', async () => {
        const savedForm = { active: false, profileDomain: null, enabledCapabilities: [], enabledTransports: ['rest'] };
        const draft = { ...UCP_FORM };
        const ctx = buildUcpContext();
        ctx.ucpState = { loaded: true, form: draft, savedForm, generation: 0 };
        ctx.ucpAdminApiService = { saveConfig: jest.fn(() => Promise.reject(new Error('write failed'))) };
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);
        ctx.$super = jest.fn(async () => {
            ctx.isSaveSuccessful = true;
            ctx.isLoading = true;
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.ucpState.form).toEqual(draft);
        expect(ctx.ucpState.savedForm).toEqual(savedForm);
        expect(ctx.isLoading).toBe(true);
        expect(ctx.isSaveSuccessful).toBe(false);
        expect(ctx.ucpAdminApiService.getProfilePreview).toBeUndefined();
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
    });

});

describe('agentic export-channel UCP changes', () => {
    it('delegates Agentic Commerce channel saves to the parent', async () => {
        const ctx = buildContext({ saveSalesChannel: jest.fn(async () => true) });
        const form = { active: false, profileDomain: null, enabledCapabilities: [], enabledTransports: ['rest'] };
        ctx.ucpState = { loaded: true, form, savedForm: { ...form }, generation: 0 };
        ctx.ucpAdminApiService = { saveConfig: jest.fn() };
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.ucpAdminApiService.saveConfig).not.toHaveBeenCalled();
    });
});
