import './acl/index.js';
import './extension/sw-sales-channel-detail-base/index.js';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

const { Module } = Shopware;

function loadComponent(importComponent) {
    return importComponent().then((componentModule) => componentModule.default || componentModule);
}

function resolveSettingsGroup() {
    const version = Shopware.Context?.app?.config?.version ?? '';

    // The registry can still be incomplete while plugin modules are registered.
    // Use the loaded administration version first so trunk/6.7 keeps the
    // commerce placement while 6.5/6.6 stay in the legacy shop group.
    if (/^6\.(5|6)\./.test(version)) {
        return 'shop';
    }

    if (/^6\./.test(version)) {
        return 'commerce';
    }

    const settingsModule = Module.getModuleRegistry().get('sw-settings');
    const settingsChildren = settingsModule?.routes?.index?.children ?? {};

    if (Object.prototype.hasOwnProperty.call(settingsChildren, 'commerce')) {
        return 'commerce';
    }

    return 'shop';
}

Shopware.Component.register('sw-settings-ucp-index', () => loadComponent(() => import('./page/sw-settings-ucp-index/index.js')));
Shopware.Component.register('sw-settings-ucp-detail', () => loadComponent(() => import('./page/sw-settings-ucp-detail/index.js')));

Module.register('sw-settings-ucp', {
    type: 'plugin',
    name: 'sw-settings-ucp',
    title: 'sw-settings-ucp.general.mainMenuItemGeneral',
    description: 'sw-settings-ucp.general.descriptionTextModule',
    color: '#3b7f8c',
    icon: 'regular-plug',
    snippets: {
        'en-GB': enGB,
        'de-DE': deDE,
    },
    routes: {
        index: {
            component: 'sw-settings-ucp-index',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'ucp.viewer',
            },
        },
        detail: {
            component: 'sw-settings-ucp-detail',
            path: 'detail/:salesChannelId',
            meta: {
                parentPath: 'sw.settings.ucp.index',
                privilege: 'ucp.viewer',
            },
        },
    },
    settingsItem: {
        group: resolveSettingsGroup(),
        to: 'sw.settings.ucp.index',
        icon: 'regular-plug',
        privilege: 'ucp.viewer',
    },
});
