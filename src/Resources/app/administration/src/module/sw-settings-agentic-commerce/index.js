import './page/sw-settings-agentic-commerce-index';
import './page/sw-settings-agentic-commerce-prepare';
import { settingsGroup } from '../../extension/sw-sales-channel/agentic-commerce/settings-group';

/**
 * Settings > Agentic Commerce, the single place that answers whether this shop
 * is ready for AI agents and what is left to do.
 *
 * Registered as a plugin module rather than by extending an existing settings
 * page, so the route survives across the Vue Router 3 and 4 lanes without the
 * child-route juggling routes.init.js needs for the sales-channel detail tabs.
 */
Shopware.Module.register('sw-settings-agentic-commerce', {
    type: 'plugin',
    name: 'settings-agentic-commerce',
    title: 'swagAgenticCommerce.settings.title',
    description: 'swagAgenticCommerce.settings.description',
    color: '#9AA8B5',
    icon: 'regular-sparkle',
    favicon: 'icon-module-settings.png',

    routes: {
        index: {
            component: 'sw-settings-agentic-commerce-index',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'ucp.viewer',
            },
        },
        prepare: {
            component: 'sw-settings-agentic-commerce-prepare',
            path: 'prepare',
            meta: {
                parentPath: 'sw.settings.agentic.commerce.index',
                privilege: 'ucp.editor',
            },
        },
    },

    settingsItem: {
        group: settingsGroup(),
        to: 'sw.settings.agentic.commerce.index',
        icon: 'regular-sparkle',
        privilege: 'ucp.viewer',
        label: 'swagAgenticCommerce.settings.title',
    },
});