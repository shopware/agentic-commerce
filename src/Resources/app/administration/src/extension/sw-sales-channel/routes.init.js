// Component names used for route resolution.
const COMPONENT_INTEGRATION = 'sw-sales-channel-detail-agentic-commerce-integration';
const COMPONENT_STATISTICS   = 'sw-sales-channel-detail-agentic-commerce-statistics';

// Returns an async component factory compatible with both Vue Router 3 and 4.
// Component.build() builds from Shopware's component registry, which is
// populated before routes are resolved.
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

// Shopware 6.5 (Vue Router 3): inject children into the module registry before
// the router is compiled. Only mutate array-form children — in 6.6+ the detail
// route children are a keyed object; overwriting it drops the core children and
// breaks the base redirect for all sales-channel types.
const registry = Shopware.Module.getModuleRegistry();
const salesChannelModule = registry.get('sw-sales-channel');

if (salesChannelModule) {
    const detailRoute = salesChannelModule.routes.get('sw.sales.channel.detail');

    if (detailRoute) {
        if (detailRoute.children === undefined || detailRoute.children === null) {
            detailRoute.children = [];
        }

        if (Array.isArray(detailRoute.children)) {
            const existingNames = detailRoute.children.map((c) => c.name);

            childRoutes.forEach((route) => {
                if (!existingNames.includes(route.name)) {
                    detailRoute.children.push(route);
                    salesChannelModule.routes.set(route.name, route);
                }
            });
        }
    }
}

// Shopware 6.6+ (Vue Router 4): the router is already compiled by the time
// viewInitialized resolves; add routes dynamically instead.
void Shopware.Application.viewInitialized.then(() => {
    const router = Shopware.Application.view?.router;

    if (!router) {
        return;
    }

    if (typeof router.hasRoute !== 'function' || typeof router.addRoute !== 'function') {
        // Vue Router 3: routes were already added synchronously above.
        return;
    }

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
