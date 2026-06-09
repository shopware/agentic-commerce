/**
 * @sw-package discovery
 *
 * Covers the override / live-state branching in
 * `saveAgenticCommerceExportConfig(salesChannelIdOverride, exportConfigOverride)`.
 *
 * - Override mode: the legacy onSave path snapshots channel id + config
 *   before $super and passes them in explicitly. The method must trust
 *   them and skip the live `this.isAgenticCommerce` check, because the
 *   legacy core path nulls `this.salesChannel` mid-save.
 * - Live-state mode: 6.7.9.0+ doesn't need snapshots — without overrides
 *   the method reads `this.salesChannel.id` and `this.isAgenticCommerce`
 *   directly.
 */

const Shopware = {
    Component: { override: jest.fn() },
    Utils: {
        object: { deepCopyObject: (obj) => JSON.parse(JSON.stringify(obj ?? {})) },
    },
    Classes: { ShopwareError: class {} },
    Context: { api: {} },
    Defaults: { agenticCommerceTypeId: 'agentic-type-id' },
};

global.Shopware = Shopware;

jest.mock(
    '../../../../../src/extension/sw-sales-channel/page/sw-sales-channel-detail/sw-sales-channel-detail.html.twig',
    () => 'mock-template',
    { virtual: true },
);

// eslint-disable-next-line import/first
const { swSalesChannelDetailOverride } = require('../../../../../src/extension/sw-sales-channel/page/sw-sales-channel-detail');

function makeEntry(values) {
    return {
        provider: 'open-ai',
        systemConfigDomain: 'SwagAgenticCommerce.openAiProductExport',
        elements: [],
        values,
        errors: {},
        isLoading: false,
        isLoaded: true,
    };
}

function buildContext({ liveAgentic = true, liveChannel = { id: 'live-id', typeId: 'agentic-type-id' } } = {}) {
    return {
        salesChannel: liveChannel,
        agenticCommerceExportConfig: [makeEntry({ live: 'live-value' })],
        isAgenticCommerce: liveAgentic,
        systemConfigApiService: { batchSave: jest.fn(() => Promise.resolve()) },
        $t: jest.fn(),
        createNotificationError: jest.fn(),
        placeholder: jest.fn(() => ''),
    };
}

const save = swSalesChannelDetailOverride.methods.saveAgenticCommerceExportConfig;

describe('saveAgenticCommerceExportConfig — snapshot override mode (legacy 6.7.0–6.7.8.x path)', () => {
    it('uses the supplied channel id and config even when this.salesChannel was nulled', async () => {
        const ctx = buildContext({ liveChannel: null, liveAgentic: false });
        const snapshotConfig = [makeEntry({ snap: 'snap-value' })];

        const ok = await save.call(ctx, 'snapshot-id', snapshotConfig);

        expect(ok).toBe(true);
        expect(ctx.systemConfigApiService.batchSave).toHaveBeenCalledWith({
            'snapshot-id': { snap: 'snap-value' },
        });
    });

    it('does not consult live this.isAgenticCommerce when an override id is provided', async () => {
        // liveAgentic=false would short-circuit in live-state mode but must not in override mode.
        const ctx = buildContext({ liveAgentic: false });
        const snapshotConfig = [makeEntry({ override: 'override-value' })];

        await save.call(ctx, 'snapshot-id', snapshotConfig);

        expect(ctx.systemConfigApiService.batchSave).toHaveBeenCalledTimes(1);
    });
});

describe('saveAgenticCommerceExportConfig — live state mode (6.7.9.0+ path)', () => {
    it('reads channel id and config from `this` when no overrides are supplied', async () => {
        const ctx = buildContext();

        await save.call(ctx);

        expect(ctx.systemConfigApiService.batchSave).toHaveBeenCalledWith({
            'live-id': { live: 'live-value' },
        });
    });

    it('short-circuits silently when this.isAgenticCommerce is false and no override is given', async () => {
        const ctx = buildContext({ liveAgentic: false });

        const ok = await save.call(ctx);

        expect(ok).toBe(true);
        expect(ctx.systemConfigApiService.batchSave).not.toHaveBeenCalled();
    });
});

describe('saveAgenticCommerceExportConfig — empty config', () => {
    it('skips the API call when no config entry is loaded', async () => {
        const ctx = buildContext();
        ctx.agenticCommerceExportConfig = [];

        const ok = await save.call(ctx);

        expect(ok).toBe(true);
        expect(ctx.systemConfigApiService.batchSave).not.toHaveBeenCalled();
    });
});
