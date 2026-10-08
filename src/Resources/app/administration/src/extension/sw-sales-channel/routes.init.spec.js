/**
 * @sw-package discovery
 *
 * The Agentic Commerce tabs are child routes of `sw.sales.channel.detail`. In every supported
 * version core loads plugins before it builds the router from the module registry, so the registry
 * is the path that ships; `router.addRoute` on a Vue Router 4 router is only a fallback. Core
 * registers the integration route itself from 6.7.10, so an existing name must never be added
 * twice. The core fixtures mirror how core's module factory stores children: an array of named
 * routes.
 */

const DETAIL_ROUTE = 'sw.sales.channel.detail';
const AGENTIC_COMMERCE = 'sw.sales.channel.detail.agenticCommerce';
const INTEGRATION = 'sw.sales.channel.detail.agenticCommerceIntegration';
const STATISTICS = 'sw.sales.channel.detail.agenticCommerceStatistics';

function coreChildren(...extraKeys) {
    return ['base', 'products', 'productComparison', 'analytics', ...extraKeys].map((key) => ({
        name: `${DETAIL_ROUTE}.${key}`,
        path: key,
        isChildren: true,
    }));
}

describe('extension/sw-sales-channel/routes.init', () => {
    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    async function load({ detailChildren, router = null }) {
        const detailRoute = { name: DETAIL_ROUTE, path: 'detail/:id', children: detailChildren };
        const routes = new Map([[DETAIL_ROUTE, detailRoute]]);
        const viewInitialized = Promise.resolve();

        global.Shopware = {
            Module: { getModuleRegistry: () => new Map([['sw-sales-channel', { routes }]]) },
            Component: { build: jest.fn((name) => Promise.resolve({ name })) },
            Application: { viewInitialized, view: { router } },
        };

        require('./routes.init');
        await viewInitialized;

        return { detailRoute, routes };
    }

    it('adds the Agentic Commerce tabs after the core tabs in the module registry', async () => {
        const { detailRoute, routes } = await load({ detailChildren: coreChildren() });

        expect(detailRoute.children.map((route) => route.name)).toEqual([
            ...coreChildren().map((route) => route.name),
            AGENTIC_COMMERCE,
            INTEGRATION,
            STATISTICS,
        ]);
        [AGENTIC_COMMERCE, INTEGRATION, STATISTICS].forEach((name) => {
            expect(routes.get(name)).toBe(detailRoute.children.find((route) => route.name === name));
        });
    });

    it('does not add a second integration tab when core already registers it', async () => {
        const { detailRoute } = await load({ detailChildren: coreChildren('agenticCommerceIntegration') });

        expect(detailRoute.children.map((route) => route.name)).toEqual([
            ...coreChildren('agenticCommerceIntegration').map((route) => route.name),
            AGENTIC_COMMERCE,
            STATISTICS,
        ]);
    });

    it('requires UCP viewer access for the Agentic Commerce and statistics tabs', async () => {
        const { routes } = await load({ detailChildren: coreChildren() });

        [AGENTIC_COMMERCE, STATISTICS].forEach((name) => {
            expect(routes.get(name).meta).toEqual({ parentPath: 'sw.sales.channel.list', privilege: 'ucp.viewer' });
        });
        expect(routes.get(INTEGRATION).meta.parentPath).toBe('sw.sales.channel.list');
    });

    it('falls back to addRoute on a Vue Router 4 router, skipping names it already has', async () => {
        const router = {
            hasRoute: jest.fn((name) => name === INTEGRATION),
            addRoute: jest.fn(),
        };

        await load({ detailChildren: coreChildren(), router });

        expect(router.addRoute.mock.calls.map(([parent, route]) => [parent, route.name, route.path])).toEqual([
            [DETAIL_ROUTE, AGENTIC_COMMERCE, 'agentic-commerce'],
            [DETAIL_ROUTE, STATISTICS, 'agentic-commerce-statistics'],
        ]);
        router.addRoute.mock.calls.forEach(([, route]) => {
            expect(route.meta).toEqual({ parentPath: 'sw.sales.channel.list', privilege: 'ucp.viewer' });
        });
    });

    it('does not add routes to a Vue Router 3 router, which the registry already covers', async () => {
        const router = { addRoute: jest.fn(), getRoutes: jest.fn(() => []) };

        await load({ detailChildren: coreChildren(), router });

        expect(router.addRoute).not.toHaveBeenCalled();
    });

    it('resolves each tab to its own Agentic Commerce component', async () => {
        const { routes } = await load({ detailChildren: coreChildren() });

        await expect(routes.get(AGENTIC_COMMERCE).component()).resolves.toEqual({ name: 'sw-sales-channel-detail-agentic-commerce' });
        await expect(routes.get(INTEGRATION).component()).resolves.toEqual({ name: 'sw-sales-channel-detail-agentic-commerce-integration' });
        await expect(routes.get(STATISTICS).component()).resolves.toEqual({ name: 'sw-sales-channel-detail-agentic-commerce-statistics' });
    });
});