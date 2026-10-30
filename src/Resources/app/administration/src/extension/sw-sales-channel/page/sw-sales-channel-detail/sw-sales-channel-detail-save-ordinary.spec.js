global.Shopware = {
    Component: { override: jest.fn() },
    Utils: { object: {} },
    Classes: {},
    Context: { api: {} },
    Defaults: {},
};

const { swSalesChannelDetailOverride } = require('./index');
const { normalizeConfig } = require('../../agentic-commerce/ucp-form-state');

function createContext() {
    const ctx = {
        isAgenticCommerce: false,
        shouldRenderAgenticCommerceTab: true,
        allowSaving: true,
        $route: { name: 'sw.sales.channel.detail.base', params: { id: 'ordinary-channel' } },
        salesChannel: { id: 'ordinary-channel' },
        ucpState: {
            loaded: true,
            form: normalizeConfig({ active: false }),
            savedForm: normalizeConfig({ active: false }),
            generation: 0,
        },
        acl: { can: jest.fn(() => true) },
        isSaveSuccessful: false,
        isLoading: false,
        validateAgenticCommerceExportConfig: jest.fn(() => true),
        agenticCommerceExportConfig: [],
        saveSalesChannel: jest.fn(async () => {
            ctx.isSaveSuccessful = true;
            ctx.isLoading = false;
            return true;
        }),
        saveAgenticCommerceExportConfig: jest.fn(async () => true),
        saveUcpState: jest.fn(async () => true),
        saveAgenticFiles: jest.fn(async () => true),
        loadEntityData: jest.fn(),
        loadUcpState: jest.fn(),
        ucpAdminApiService: { getProfilePreview: jest.fn(async () => ({ data: { data: { active: true } } })) },
    };
    ctx.$super = jest.fn(async () => {
        ctx.isSaveSuccessful = true;
    });
    Object.entries(swSalesChannelDetailOverride.computed).forEach(([name, getter]) => {
        if (!(name in ctx)) {
            Object.defineProperty(ctx, name, { get: () => getter.call(ctx) });
        }
    });
    ctx.refreshUcpPreview = swSalesChannelDetailOverride.methods.refreshUcpPreview.bind(ctx);
    return ctx;
}

describe('ordinary sales-channel saves', () => {
    it.each(['base', 'agenticCommerce'])('saves pending sales-channel and UCP drafts from the %s tab', async (tab) => {
        const ctx = createContext();
        ctx.$route.name = `sw.sales.channel.detail.${tab}`;
        ctx.salesChannel.name = 'Updated channel';
        ctx.salesChannel.domains = [{ url: 'https://updated.example' }];
        ctx.salesChannel.salesChannelFiles = [{ enabled: false }];
        ctx.ucpState.form.active = true;
        const persisted = {};
        ctx.$super.mockImplementation(async () => {
            Object.assign(persisted, JSON.parse(JSON.stringify(ctx.salesChannel)));
            ctx.salesChannel = null;
            ctx.isSaveSuccessful = true;
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(persisted).toMatchObject({
            name: 'Updated channel',
            domains: [{ url: 'https://updated.example' }],
            salesChannelFiles: [{ enabled: false }],
        });
        expect(ctx.saveUcpState).toHaveBeenCalledWith('ordinary-channel', expect.objectContaining({ active: true }));
        expect(ctx.$super.mock.invocationCallOrder[0]).toBeLessThan(ctx.saveUcpState.mock.invocationCallOrder[0]);
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it.each([true, false])('blocks repeated saves after the entity reload until UCP finishes with %s', async (saved) => {
        const ctx = createContext();
        ctx.ucpState.form.active = true;
        let finishReload;
        const reload = new Promise((resolve) => { finishReload = resolve; });
        ctx.salesChannelRepository = { get: jest.fn(() => reload) };
        ctx.getLoadSalesChannelCriteria = jest.fn(() => ({}));
        ctx.generateAccessUrl = jest.fn();
        ctx.loadAgenticCommerceExportConfig = jest.fn();
        ctx.$super.mockImplementation(async () => {
            ctx.isSaveSuccessful = true;
            swSalesChannelDetailOverride.methods.loadSalesChannel.call(ctx);
        });
        let finishWrite;
        ctx.saveUcpState.mockImplementation(() => new Promise((resolve) => { finishWrite = resolve; }));
        const firstSave = swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await Promise.resolve();

        expect(ctx.isLoading).toBe(true);
        expect(ctx.isSaveSuccessful).toBe(false);
        finishReload({ id: 'ordinary-channel', maintenanceIpWhitelist: [] });
        await reload;
        expect(ctx.isLoading).toBe(false);

        const secondSave = swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await Promise.resolve();
        expect(ctx.$super).toHaveBeenCalledTimes(1);
        expect(ctx.saveUcpState).toHaveBeenCalledTimes(1);
        expect(ctx.isSavingUcp).toBe(true);

        finishWrite(saved);
        await Promise.all([firstSave, secondSave]);
        expect(ctx.isSavingUcp).toBe(false);
        expect(ctx.isSaveSuccessful).toBe(saved);
    });

    it('honors parent validation when saving from the Agentic Commerce tab', async () => {
        const ctx = createContext();
        ctx.$route.name = 'sw.sales.channel.detail.agenticCommerce';
        ctx.ucpState.form.active = true;
        ctx.$super.mockImplementation(async () => {});

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.isSaveSuccessful).toBe(false);
    });

    it('leaves the save and reload to the parent without writing unchanged UCP settings', async () => {
        const ctx = createContext();

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.$super).toHaveBeenCalledWith('onSave');
        expect(ctx.saveSalesChannel).not.toHaveBeenCalled();
        expect(ctx.validateAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.loadEntityData).not.toHaveBeenCalled();
        expect(ctx.loadUcpState).not.toHaveBeenCalled();
        expect(ctx.ucpAdminApiService.getProfilePreview).not.toHaveBeenCalled();
    });

    it('does not wait for a UCP write between the parent save and its reload', async () => {
        const ctx = createContext();
        const events = [];
        ctx.$super.mockImplementation(async () => {
            events.push('parent save', 'parent reload');
            ctx.isSaveSuccessful = true;
        });
        ctx.saveUcpState.mockImplementation(() => {
            events.push('UCP write');
            return Promise.resolve(true);
        });
        ctx.loadEntityData.mockImplementation(() => events.push('extension reload'));

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(events).toEqual(['parent save', 'parent reload']);
    });

    it('persists a pending UCP draft from another tab even if the parent reload replaces the form', async () => {
        const ctx = createContext();
        ctx.ucpState.form.active = true;
        const draft = {
            active: true,
            profileDomain: null,
            enabledCapabilities: ['catalog', 'cart', 'discount', 'checkout', 'order'],
            enabledTransports: ['rest'],
        };
        ctx.$super.mockImplementation(async () => {
            ctx.salesChannel = null;
            ctx.ucpState.form = normalizeConfig({ active: false });
            ctx.isSaveSuccessful = true;
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).toHaveBeenCalledWith('ordinary-channel', draft);
        expect(ctx.loadEntityData).not.toHaveBeenCalled();
        expect(ctx.loadUcpState).not.toHaveBeenCalled();
        expect(ctx.ucpAdminApiService.getProfilePreview).toHaveBeenCalledWith('ordinary-channel');
        expect(ctx.ucpState.preview).toEqual({ active: true });
    });

    it('does not write a pending draft after parent validation aborts a later save', async () => {
        const ctx = createContext();
        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        expect(ctx.isSaveSuccessful).toBe(true);
        ctx.ucpState.form.active = true;
        ctx.$super.mockImplementation(async () => {});

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.isSaveSuccessful).toBe(false);
        expect(ctx.saveUcpState).not.toHaveBeenCalled();
    });

    it('does not write UCP settings that have not loaded', async () => {
        const ctx = createContext();
        ctx.ucpState.loaded = false;
        ctx.ucpState.form.active = true;

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
    });

    it('compares normalized settings instead of legacy response keys or missing defaults', async () => {
        const ctx = createContext();
        ctx.ucpState.savedForm = { active: false, profileDomain: '', legacyOption: 'ignored' };

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
    });

    it('does not write a pending UCP draft when sales-channel persistence fails', async () => {
        const ctx = createContext();
        ctx.ucpState.form.active = true;
        ctx.$super.mockImplementation(async () => {
            ctx.isSaveSuccessful = false;
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.ucpAdminApiService.getProfilePreview).not.toHaveBeenCalled();
    });

    it('reconciles a successful draft write and skips it on the next ordinary save', async () => {
        const ctx = createContext();
        ctx.ucpState.form.active = true;
        ctx.ucpAdminApiService.saveConfig = jest.fn(async () => {});
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);
        ctx.reloadAgenticFiles = swSalesChannelDetailOverride.methods.reloadAgenticFiles.bind(ctx);
        ctx.createNotificationError = jest.fn();

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.ucpAdminApiService.saveConfig).toHaveBeenCalledTimes(1);
        expect(ctx.ucpAdminApiService.getProfilePreview).toHaveBeenCalledTimes(1);
        expect(ctx.ucpState.preview).toEqual({ active: true });
        expect(ctx.ucpState.form).toEqual(ctx.ucpState.savedForm);
        expect(ctx.$super).toHaveBeenCalledTimes(2);
    });

    it('keeps a refused UCP draft when the parent reload returns its old config later', async () => {
        const ctx = createContext();
        ctx.shouldRenderAgenticCommerceTab = true;
        ctx.ucpState.form.active = true;
        const draft = {
            active: true,
            profileDomain: null,
            enabledCapabilities: ['catalog', 'cart', 'discount', 'checkout', 'order'],
            enabledTransports: ['rest'],
        };
        let resolveConfig;
        const pendingConfig = new Promise((resolve) => { resolveConfig = resolve; });
        ctx.ucpAdminApiService = {
            getSalesChannel: jest.fn(async () => ({ data: { data: { transactional: true } } })),
            getConfig: jest.fn(() => pendingConfig),
            getProfilePreview: jest.fn(async () => ({ data: { data: null } })),
            saveConfig: jest.fn(async () => { throw new Error('UCP write refused'); }),
        };
        ctx.createNotificationError = jest.fn();
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);
        let reload;
        ctx.$super.mockImplementation(async () => {
            reload = swSalesChannelDetailOverride.methods.loadUcpState.call(ctx, 'ordinary-channel');
            ctx.isSaveSuccessful = true;
        });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        resolveConfig({ data: { data: { active: false } } });
        await reload;

        expect(ctx.isSaveSuccessful).toBe(false);
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
        expect(ctx.ucpState.form).toEqual(draft);
        expect(ctx.ucpState.savedForm.active).toBe(false);
    });
});
