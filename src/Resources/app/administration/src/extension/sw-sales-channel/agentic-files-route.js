const AGENTIC_COMMERCE_ROUTE = 'sw.sales.channel.detail.agenticCommerce';
const AGENTIC_FILES_ROUTE = 'sw.sales.channel.detail.agenticFiles';

export function getAgenticFileListRoute(salesChannelId, shouldRenderAgenticCommerceTab) {
    return {
        name: shouldRenderAgenticCommerceTab
            ? AGENTIC_COMMERCE_ROUTE
            : AGENTIC_FILES_ROUTE,
        params: {
            id: salesChannelId,
        },
    };
}
