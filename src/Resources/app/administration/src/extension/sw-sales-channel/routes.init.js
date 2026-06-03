/**
 * Registers the Agentic Commerce detail sub-routes.
 *
 * Strategy differs by Vue Router version:
 *
 * Shopware 6.6+ (Vue Router 4): `viewInitialized` resolves after the router is
 * already created. Use `router.addRoute(parent, child)` with a `hasRoute` guard.
 *
 * Shopware 6.5 (Vue Router 3): `addRoute` doesn't exist; `addRoutes` does, but
 * passing a duplicate parent name removes its redirect and breaks navigation.
 * Instead, mutate the module-registry route record synchronously — this runs
 * before the router is compiled from the module routes, so the children are
 * included from the start with no redirect side-effects.
 */

// Component names used for route resolution.
const COMPONENT_INTEGRATION = 'sw-sales-channel-detail-agentic-commerce-integration';
const COMPONENT_STATISTICS   = 'sw-sales-channel-detail-agentic-commerce-statistics';

/**
 * Returns an async component factory suitable for both Vue Router 3 and 4.
 *
 * Vue Router 3 calls the factory as `def(resolve, reject)`. If the function
 * returns a Promise, Vue Router calls `res.then(resolve, reject)` — so
 * returning `Shopware.Component.build()` (which IS a Promise) works perfectly.
 *
 * view.getComponent() cannot be used here because it looks in vueComponents
 * which is only populated by initComponents(). Plugin view components register
 * after that phase so they are never found there. Component.build() builds
 * directly from Shopware's component registry, which IS populated.
 */
function makeComponentFactory(componentName) {
    return () => Shopware.Component.build(componentName);
}

const childRoutes = [
    {
        name: 'sw.sales.channel.detail.agenticCommerceIntegration',
        path: 'agentic-commerce-integration',
        component: makeComponentFactory(COMPONENT_INTEGRATION),
        isChildren: true,
        meta: {
            parentPath: 'sw.sales.channel.list',
            privilege: 'sales_channel.viewer',
        },
    },
    {
        name: 'sw.sales.channel.detail.agenticCommerceStatistics',
        path: 'agentic-commerce-statistics',
        component: makeComponentFactory(COMPONENT_STATISTICS),
        isChildren: true,
        meta: {
            parentPath: 'sw.sales.channel.list',
            privilege: 'sales_channel.viewer',
        },
    },
];

// ── Shopware 6.5 (Vue Router 3): synchronous module-registry injection ──────
// The module factory has already processed sw-sales-channel routes into a flat
// Map keyed by route name. We find the detail record and push our children in
// before getModuleRoutes() is called to build the Vue Router config.
const registry = Shopware.Module.getModuleRegistry();
const salesChannelModule = registry.get('sw-sales-channel');

if (salesChannelModule) {
    const detailRoute = salesChannelModule.routes.get('sw.sales.channel.detail');

    if (detailRoute) {
        if (!Array.isArray(detailRoute.children)) {
            detailRoute.children = [];
        }

        const existingNames = detailRoute.children.map((c) => c.name);

        childRoutes.forEach((route) => {
            if (!existingNames.includes(route.name)) {
                detailRoute.children.push(route);
                // Register in the flat routes Map so getModuleRoutes() can see it
                salesChannelModule.routes.set(route.name, route);
            }
        });
    }
}

// ── Shopware 6.6+ (Vue Router 4): dynamic injection after view is ready ─────
// In 6.6+, the module-registry detail record may already have been compiled
// into the router. Fall back to router.addRoute() with a hasRoute guard.
void Shopware.Application.viewInitialized.then(() => {
    const router = Shopware.Application.view?.router;

    if (!router) {
        return;
    }

    // Suppress NavigationDuplicated — Vue Router 3 throws when navigating to
    // the current route; Vue Router 4 silently ignores it. Align behaviour.
    if (typeof router.onError === 'function') {
        router.onError((err) => {
            if (err && err.name === 'NavigationDuplicated') {
                return;
            }
            throw err;
        });
    }

    if (typeof router.hasRoute !== 'function' || typeof router.addRoute !== 'function') {
        // Vue Router 3: routes were already added synchronously above.
        return;
    }

    // Vue Router 4: add any routes not yet registered.
    childRoutes.forEach((route) => {
        if (!router.hasRoute(route.name)) {
            router.addRoute('sw.sales.channel.detail', {
                name: route.name,
                path: route.path,
                component: route.component,
                meta: route.meta,
            });
        }
    });
});
