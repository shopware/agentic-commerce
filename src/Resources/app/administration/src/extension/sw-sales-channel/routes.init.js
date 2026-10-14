const COMPONENT_AGENTIC     = 'sw-sales-channel-detail-agentic-commerce';
const COMPONENT_STATISTICS  = 'sw-sales-channel-detail-agentic-commerce-statistics';
const COMPONENT_INTEGRATION = 'sw-sales-channel-detail-agentic-commerce-integration';

// Returns an async component factory compatible with both Vue Router 3 and 4.
// Component.build() builds from Shopware's component registry, which is
// populated before routes are resolved.
function makeComponentFactory(componentName) {
    return async () => {
        return await Shopware.Component.build(componentName);
    };
}

const childRoutes = [
    {
        name: 'sw.sales.channel.detail.agenticCommerce',
        path: 'agentic-commerce',
        component: makeComponentFactory(COMPONENT_AGENTIC),
        isChildren: true,
        meta: {
            parentPath: 'sw.sales.channel.list',
            privilege: 'ucp.viewer',
        },
    },
    {
        // Core registers this same name from 6.7.10; the guards below skip it there.
        name: 'sw.sales.channel.detail.agenticCommerceIntegration',
        path: 'agentic-commerce-integration',
        component: makeComponentFactory(COMPONENT_INTEGRATION),
        isChildren: true,
        meta: {
            parentPath: 'sw.sales.channel.list',
        },
    },
    {
        name: 'sw.sales.channel.detail.agenticCommerceStatistics',
        path: 'agentic-commerce-statistics',
        component: makeComponentFactory(COMPONENT_STATISTICS),
        isChildren: true,
        meta: {
            parentPath: 'sw.sales.channel.list',
            privilege: 'ucp.viewer',
        },
    },
];

// Every supported version: inject children into the module registry before core
// builds the router from it. Core's module factory stores the detail route
// children as an array of named routes. Only mutate array-form children;
// replacing children of any other shape drops the core children and breaks the
// base redirect for all sales-channel types.
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

// Fallback for a Vue Router 4 router that was built before the registry path ran:
// add any route it does not have yet.
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
