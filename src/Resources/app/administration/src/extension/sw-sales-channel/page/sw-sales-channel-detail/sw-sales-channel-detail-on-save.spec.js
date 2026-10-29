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

function createFileRepository(initialFiles = [], { rejectDuplicateNewIds = false, onSave = () => {} } = {}) {
    const persistedFiles = new Map(initialFiles.map(({ id, data }) => [id, { ...data }]));
    const repository = {
        hasChanges: jest.fn((configuration) => configuration.getIsDirty()),
        save: jest.fn(async (configuration) => {
            onSave(configuration);

            if (rejectDuplicateNewIds && configuration.isNew() && persistedFiles.has(configuration.id)) {
                throw new Error('FRAMEWORK__WRITE_TYPE_INTEND_ERROR');
            }

            persistedFiles.set(configuration.id, { ...configuration.getDraft() });
        }),
        get: jest.fn(async (id) => createFileEntity(id, persistedFiles.get(id))),
    };

    return { repository, persistedFiles };
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
    ctx.saveAgenticFiles = swSalesChannelDetailOverride.methods.saveAgenticFiles.bind(ctx);
    ctx.refreshUcpPreview = swSalesChannelDetailOverride.methods.refreshUcpPreview.bind(ctx);

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

    it('reconciles backend defaults after UCP activation so a later toggle updates the existing file', async () => {
        const fileData = {
            salesChannelId: 'channel-id',
            fileFamily: 'agentic',
            fileName: 'agents.md',
            enabled: true,
            templateOverrides: {},
        };
        const existingFile = createFileEntity('backend-default-id', fileData);
        const { repository, persistedFiles } = createFileRepository([
            { id: 'backend-default-id', data: fileData },
        ]);
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.ucpState = {
            loaded: true,
            form: { ...UCP_FORM },
            savedForm: { ...UCP_FORM, active: false },
            generation: 0,
        };
        ctx.salesChannel.salesChannelFiles = [];
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);
        ctx.ucpAdminApiService = {
            saveConfig: jest.fn(() => Promise.resolve()),
            getProfilePreview: jest.fn(() => Promise.resolve({ data: { data: null } })),
        };
        ctx.salesChannelRepository = {
            get: jest.fn(() => Promise.resolve({ salesChannelFiles: [existingFile] })),
        };
        ctx.getLoadSalesChannelCriteria = jest.fn(() => ({}));
        ctx.reloadAgenticFiles = swSalesChannelDetailOverride.methods.reloadAgenticFiles.bind(ctx);
        ctx.repositoryFactory = { create: jest.fn(() => repository) };
        Shopware.Component.getComponentRegistry.mockReturnValueOnce({ has: () => true });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.salesChannelRepository.get).toHaveBeenCalledWith('channel-id', Shopware.Context.api, {});
        expect(ctx.salesChannel.salesChannelFiles).toEqual([existingFile]);

        ctx.salesChannel.salesChannelFiles[0].enabled = false;
        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(repository.save).toHaveBeenCalledWith(existingFile, Shopware.Context.api);
        expect(existingFile.isNew()).toBe(false);
        expect(persistedFiles.get('backend-default-id').enabled).toBe(false);
        expect(ctx.isSaveSuccessful).toBe(true);
    });

    it('recovers Agentic File edits after one failed activation refresh', async () => {
        const fileData = {
            salesChannelId: 'channel-id',
            fileFamily: 'agentic',
            fileName: 'agents.md',
            enabled: true,
            templateOverrides: {},
        };
        const writes = [];
        const { repository, persistedFiles } = createFileRepository(
            [{ id: 'backend-default-id', data: fileData }],
            {
                rejectDuplicateNewIds: true,
                onSave: (configuration) => writes.push({ isNew: configuration.isNew(), enabled: configuration.enabled }),
            },
        );
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.ucpState = {
            loaded: true,
            form: { ...UCP_FORM },
            savedForm: { ...UCP_FORM, active: false },
            generation: 0,
        };
        ctx.salesChannel.salesChannelFiles = [];
        ctx.ucpAdminApiService = {
            saveConfig: jest.fn(async () => {}),
            getProfilePreview: jest.fn(async () => ({ data: { data: null } })),
        };
        const refreshError = new Error('refresh failed');
        const loadSalesChannel = jest.fn()
            .mockRejectedValueOnce(refreshError)
            .mockResolvedValue({ salesChannelFiles: [createFileEntity('backend-default-id', fileData)] });
        ctx.salesChannelRepository = { get: loadSalesChannel };
        ctx.getLoadSalesChannelCriteria = jest.fn(() => ({}));
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);
        ctx.reloadAgenticFiles = swSalesChannelDetailOverride.methods.reloadAgenticFiles.bind(ctx);
        ctx.repositoryFactory = { create: jest.fn(() => repository) };
        Shopware.Component.getComponentRegistry.mockReturnValueOnce({ has: () => true });
        Shopware.Component.getComponentRegistry.mockReturnValueOnce({ has: () => true });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.isSaveSuccessful).toBe(true);
        expect(ctx.ucpState.savedForm.active).toBe(true);
        expect(ctx.createNotificationError).not.toHaveBeenCalled();

        // Core creates a new draft with the list API's existing ID when the collection is stale.
        const fileDraft = createFileEntity('backend-default-id', fileData, true);
        fileDraft.enabled = false;
        fileDraft.templateOverrides = { content: 'Merchant instructions' };
        ctx.salesChannel.salesChannelFiles.push(fileDraft);
        const newFile = createFileEntity('new-file', { ...fileData, fileName: 'custom.md', enabled: false }, true);
        ctx.salesChannel.salesChannelFiles.push(newFile);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.isSaveSuccessful).toBe(true);
        expect(ctx.isLoading).toBe(false);
        expect(ctx.salesChannel.salesChannelFiles).toHaveLength(2);
        expect(ctx.salesChannel.salesChannelFiles.map((file) => file.id)).toEqual(['backend-default-id', 'new-file']);
        expect(ctx.ucpAdminApiService.saveConfig).toHaveBeenCalledTimes(2);
        expect(repository.save).toHaveBeenCalledTimes(2);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.isSaveSuccessful).toBe(true);
        expect(writes).toEqual([{ isNew: false, enabled: false }, { isNew: true, enabled: false }]);
        expect(persistedFiles.get('backend-default-id')).toMatchObject({
            enabled: false,
            templateOverrides: { content: 'Merchant instructions' },
        });
        expect(persistedFiles.get('new-file').enabled).toBe(false);
        expect(loadSalesChannel).toHaveBeenCalledTimes(2);
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(0);
    });

    it('recovers Agentic File edits after two failed activation refreshes', async () => {
        const fileData = {
            salesChannelId: 'channel-id',
            fileFamily: 'agentic',
            fileName: 'agents.md',
            enabled: true,
            templateOverrides: {},
        };
        const writes = [];
        const { repository, persistedFiles } = createFileRepository(
            [{ id: 'backend-default-id', data: fileData }],
            {
                rejectDuplicateNewIds: true,
                onSave: (configuration) => writes.push({ isNew: configuration.isNew(), enabled: configuration.enabled }),
            },
        );
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.ucpState = {
            loaded: true,
            form: { ...UCP_FORM },
            savedForm: { ...UCP_FORM, active: false },
            generation: 0,
        };
        ctx.salesChannel.salesChannelFiles = [];
        ctx.ucpAdminApiService = {
            saveConfig: jest.fn(async () => {}),
            getProfilePreview: jest.fn(async () => ({ data: { data: null } })),
        };
        const refreshError = new Error('refresh failed');
        const loadSalesChannel = jest.fn()
            .mockRejectedValueOnce(refreshError)
            .mockRejectedValueOnce(refreshError)
            .mockResolvedValue({ salesChannelFiles: [createFileEntity('backend-default-id', fileData)] });
        ctx.salesChannelRepository = { get: loadSalesChannel };
        ctx.getLoadSalesChannelCriteria = jest.fn(() => ({}));
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);
        ctx.reloadAgenticFiles = swSalesChannelDetailOverride.methods.reloadAgenticFiles.bind(ctx);
        ctx.repositoryFactory = { create: jest.fn(() => repository) };
        Shopware.Component.getComponentRegistry.mockReturnValueOnce({ has: () => true });
        Shopware.Component.getComponentRegistry.mockReturnValueOnce({ has: () => true });
        Shopware.Component.getComponentRegistry.mockReturnValueOnce({ has: () => true });

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(loadSalesChannel).toHaveBeenCalledTimes(1);
        expect(ctx.isSaveSuccessful).toBe(true);
        expect(ctx.isLoading).toBe(false);

        const fileDraft = createFileEntity('backend-default-id', fileData, true);
        fileDraft.enabled = false;
        fileDraft.templateOverrides = { content: 'Merchant instructions' };
        ctx.salesChannel.salesChannelFiles.push(fileDraft);
        const newFile = createFileEntity('new-file', { ...fileData, fileName: 'custom.md', enabled: false }, true);
        ctx.salesChannel.salesChannelFiles.push(newFile);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.isSaveSuccessful).toBe(false);
        expect(ctx.isLoading).toBe(false);
        expect(ctx.salesChannel.salesChannelFiles).toEqual([fileDraft, newFile]);
        expect(ctx.ucpAdminApiService.saveConfig).toHaveBeenCalledTimes(1);
        expect(repository.save).not.toHaveBeenCalled();
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.isSaveSuccessful).toBe(true);
        expect(writes).toEqual([{ isNew: false, enabled: false }, { isNew: true, enabled: false }]);
        expect(persistedFiles.get('backend-default-id')).toMatchObject({
            enabled: false,
            templateOverrides: { content: 'Merchant instructions' },
        });
        expect(persistedFiles.get('new-file').enabled).toBe(false);
        expect(loadSalesChannel).toHaveBeenCalledTimes(3);
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
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
        ctx.isUcpConfigRoute = false;
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
            createNotificationError: jest.fn(),
        };

        const saved = await swSalesChannelDetailOverride.methods.saveUcpState.call(ctx, 'channel-id', UCP_FORM);

        expect(saved).toBe(false);
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
        expect(ctx.ucpState.form).toEqual(UCP_FORM);
        expect(ctx.ucpState.savedForm).toEqual(storedForm);
        expect(ctx.ucpState.generation).toBe(4);
    });

    it('uses only the UCP write on the UCP tab and refreshes the saved preview', async () => {
        const draft = { ...UCP_FORM };
        const preview = { data: { data: { ucp: { version: '1.0' } } } };
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.ucpState = { loaded: true, form: draft, savedForm: {}, generation: 0 };
        ctx.ucpAdminApiService = { getProfilePreview: jest.fn(() => Promise.resolve(preview)) };
        ctx.saveUcpState = jest.fn(() => Promise.resolve(true));

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);
        await Promise.resolve();

        expect(ctx.saveUcpState).toHaveBeenCalledWith('channel-id', draft);
        expect(ctx.saveSalesChannel).toBeUndefined();
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.validateAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.ucpAdminApiService.getProfilePreview).toHaveBeenCalledWith('channel-id');
        expect(ctx.ucpState.preview).toEqual(preview.data.data);
        expect(ctx.isSaveSuccessful).toBe(true);
        expect(ctx.isLoading).toBe(false);
    });

    it('persists changed Agentic Files configurations without saving the sales channel', async () => {
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
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
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
        expect(ctx.saveSalesChannel).toBeUndefined();
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
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
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
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
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
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.allowSaving = false;
        ctx.ucpState.loaded = true;
        ctx.saveAgenticFiles = jest.fn();

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.saveAgenticFiles).not.toHaveBeenCalled();
        expect(ctx.saveAgenticCommerceExportConfig).not.toHaveBeenCalled();
        expect(ctx.$super).not.toHaveBeenCalled();
    });

    it('does not save from the UCP tab before configuration has loaded', async () => {
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.ucpState.loaded = false;
        ctx.saveAgenticFiles = jest.fn();

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.saveAgenticFiles).not.toHaveBeenCalled();
        expect(ctx.isLoading).toBe(false);
    });

    it('does not start another UCP save while the page is loading', async () => {
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.isLoading = true;
        ctx.ucpState.loaded = true;
        ctx.pendingAgenticFilesReload = 'channel-id';
        ctx.reloadAgenticFiles = jest.fn();

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.saveUcpState).not.toHaveBeenCalled();
        expect(ctx.reloadAgenticFiles).not.toHaveBeenCalled();
        expect(ctx.isLoading).toBe(true);
    });

    it('keeps the draft after a failed UCP save and ends the loading state', async () => {
        const savedForm = { active: false, profileDomain: null, enabledCapabilities: [], enabledTransports: ['rest'] };
        const draft = { ...UCP_FORM };
        const ctx = buildContext();
        ctx.isUcpConfigRoute = true;
        ctx.ucpState = { loaded: true, form: draft, savedForm, generation: 0 };
        ctx.ucpAdminApiService = { saveConfig: jest.fn(() => Promise.reject(new Error('write failed'))) };
        ctx.saveUcpState = swSalesChannelDetailOverride.methods.saveUcpState.bind(ctx);

        await swSalesChannelDetailOverride.methods.onSave.call(ctx);

        expect(ctx.ucpState.form).toEqual(draft);
        expect(ctx.ucpState.savedForm).toEqual(savedForm);
        expect(ctx.isLoading).toBe(false);
        expect(ctx.isSaveSuccessful).toBe(false);
        expect(ctx.ucpAdminApiService.getProfilePreview).toBeUndefined();
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
    });

});
