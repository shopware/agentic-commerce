import { getAgenticFileDetailRoute, isAgenticFileDetailRoute } from '../../agentic-files-route';

const COMPONENT_NAME = 'sw-sales-channel-detail-agentic-files';
const componentRegistry = Shopware.Component.getComponentRegistry?.();

if (componentRegistry?.has(COMPONENT_NAME)) {
    Shopware.Component.override(COMPONENT_NAME, {
        methods: {
            getDetailRoute(file) {
                const detailRoute = this.$super('getDetailRoute', file);

                if (!isAgenticFileDetailRoute(detailRoute)) {
                    return detailRoute;
                }

                return getAgenticFileDetailRoute(this.$route, detailRoute);
            },
        },
    });
}
