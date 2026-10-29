const AGENTIC_COMMERCE_ROUTE = 'sw.sales.channel.detail.agenticCommerce';
const AGENTIC_FILES_ROUTE = 'sw.sales.channel.detail.agenticFiles';
const AGENTIC_FILE_DETAIL_ROUTE = 'sw.sales.channel.detail.agenticFile';
const AGENTIC_COMMERCE_SOURCE_QUERY = 'swagAgenticCommerceFrom';
const AGENTIC_COMMERCE_SOURCE_VALUE = 'agentic-commerce';

export function getAgenticFileDetailRoute(sourceRoute, fileRoute) {
    if (sourceRoute.name !== AGENTIC_COMMERCE_ROUTE) {
        return fileRoute;
    }

    return {
        ...fileRoute,
        query: {
            ...(fileRoute.query ?? {}),
            [AGENTIC_COMMERCE_SOURCE_QUERY]: AGENTIC_COMMERCE_SOURCE_VALUE,
        },
    };
}

export function getAgenticFileListRoute(currentRoute, salesChannelId) {
    return {
        name: currentRoute.query?.[AGENTIC_COMMERCE_SOURCE_QUERY] === AGENTIC_COMMERCE_SOURCE_VALUE
            ? AGENTIC_COMMERCE_ROUTE
            : AGENTIC_FILES_ROUTE,
        params: {
            id: salesChannelId,
        },
    };
}

export function isAgenticFileDetailRoute(route) {
    return route.name === AGENTIC_FILE_DETAIL_ROUTE;
}
