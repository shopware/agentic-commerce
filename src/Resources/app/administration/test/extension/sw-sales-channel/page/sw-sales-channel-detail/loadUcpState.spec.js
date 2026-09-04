/**
 * @sw-package discovery
 *
 * Covers the failure isolation in `loadUcpState()`.
 *
 * The method fetches three independent things: the sales-channel view (which
 * carries `meta`), the saved config, and the profile preview. `meta` reports
 * what the *platform* supports — most visibly `supportsStoreApiMcp`, which
 * decides whether MCP is offered as a transport at all.
 *
 * Under `Promise.all` a single rejection discarded all three, so a failing
 * preview call left `meta` as `{}` and the form silently rendered as if the
 * platform had no Store-API MCP server. That is indistinguishable from a shop
 * that has the `MCP_SERVER` feature flag switched off, so the merchant got a
 * plausible-looking but wrong transport list instead of an error they could act
 * on. Each response must now be applied on its own.
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

const loadUcpState = swSalesChannelDetailOverride.methods.loadUcpState;

const META = { shopwareVersion: '6.7.13.0', supportsStoreApiMcp: true };

function response(body) {
    return Promise.resolve({ data: body });
}

/** Lets already-settled promises run their handlers without advancing real time. */
async function flushMicrotasks() {
    for (let i = 0; i < 5; i += 1) {
        await Promise.resolve();
    }
}

function rejection(detail) {
    return Promise.reject({ response: { data: { errors: [{ detail }] } } });
}

function buildContext({ salesChannel, config, preview } = {}) {
    return {
        shouldRenderAgenticCommerceTab: true,
        salesChannel: { id: 'sales-channel-id', typeId: 'agentic-type-id' },
        ucpState: {
            loaded: false,
            isLoading: false,
            form: {},
            savedForm: {},
            meta: {},
            preview: null,
        },
        ucpAdminApiService: {
            getSalesChannel: jest.fn(() => salesChannel ?? response({ data: {}, meta: META })),
            getConfig: jest.fn(() => config ?? response({ data: { active: true } })),
            getProfilePreview: jest.fn(() => preview ?? response({ data: { profile: 'preview' } })),
        },
        createNotificationError: jest.fn(),
    };
}

describe('loadUcpState — platform meta survives sibling failures', () => {
    it('keeps meta when the profile preview fails', async () => {
        const ctx = buildContext({ preview: rejection('preview exploded') });

        await loadUcpState.call(ctx);

        expect(ctx.ucpState.meta).toEqual(META);
        expect(ctx.ucpState.preview).toBeNull();
        // The form still loaded, so the merchant does not lose the whole tab.
        expect(ctx.ucpState.loaded).toBe(true);
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
    });

    it('keeps meta when the config request fails', async () => {
        const ctx = buildContext({ config: rejection('config exploded') });

        await loadUcpState.call(ctx);

        expect(ctx.ucpState.meta).toEqual(META);
        // Without config there is nothing trustworthy to edit.
        expect(ctx.ucpState.loaded).toBe(false);
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
    });

    it('reports an error rather than silently claiming MCP is unsupported when meta itself fails', async () => {
        const ctx = buildContext({ salesChannel: rejection('meta exploded') });

        await loadUcpState.call(ctx);

        expect(ctx.ucpState.meta).toEqual({});
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
    });

    it('applies all three and raises nothing on the happy path', async () => {
        const ctx = buildContext();

        await loadUcpState.call(ctx);

        expect(ctx.ucpState.meta).toEqual(META);
        expect(ctx.ucpState.preview).toEqual({ profile: 'preview' });
        expect(ctx.ucpState.loaded).toBe(true);
        expect(ctx.ucpState.isLoading).toBe(false);
        expect(ctx.createNotificationError).not.toHaveBeenCalled();
    });

    it('reports a rejection without waiting for a request that is still pending', async () => {
        // The whole point of splitting the requests: a slow one must not hold back a fast
        // one. Gating on all three (Promise.all or allSettled alike) delays the error and
        // leaves the spinner turning until the slowest request finally settles.
        let resolvePreview;
        const pendingPreview = new Promise((resolve) => {
            resolvePreview = resolve;
        });

        const ctx = buildContext({ config: rejection('config exploded'), preview: pendingPreview });

        // Deliberately not awaited — the preview never settles yet.
        const done = loadUcpState.call(ctx);
        await flushMicrotasks();

        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
        expect(ctx.ucpState.isLoading).toBe(false);
        // The meta call resolved on its own, so the platform capabilities are already applied.
        expect(ctx.ucpState.meta).toEqual(META);

        // A late response is still applied rather than dropped.
        resolvePreview({ data: { data: { profile: 'late' } } });
        await done;

        expect(ctx.ucpState.preview).toEqual({ profile: 'late' });
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
    });

    it('always clears the loading flag, even when every request fails', async () => {
        const ctx = buildContext({
            salesChannel: rejection('a'),
            config: rejection('b'),
            preview: rejection('c'),
        });

        await loadUcpState.call(ctx);

        expect(ctx.ucpState.isLoading).toBe(false);
        expect(ctx.createNotificationError).toHaveBeenCalledTimes(1);
    });
});
