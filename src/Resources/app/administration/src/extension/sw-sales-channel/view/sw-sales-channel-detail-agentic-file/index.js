import { getAgenticFileListRoute } from '../../agentic-files-route';

const COMPONENT_NAME = 'sw-sales-channel-detail-agentic-file';

const componentRegistry = Shopware.Component.getComponentRegistry?.();

if (componentRegistry?.has(COMPONENT_NAME)) {
    Shopware.Component.override(COMPONENT_NAME, {
        computed: {
            listRoute() {
                return getAgenticFileListRoute(this.$route, this.$route.params.id);
            },
        },
    });
}
