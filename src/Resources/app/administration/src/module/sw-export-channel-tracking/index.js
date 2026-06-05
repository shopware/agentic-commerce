import './mixin/export-channel-filter.mixin';
import './extension/sw-order-list';
import './extension/sw-customer-list';

Shopware.Module.register('sw-export-channel-tracking', {
    type: 'core',
    name: 'export-channel-tracking',
    title: 'sw-export-channel-tracking.general.mainMenuItemGeneral',
    description: 'sw-export-channel-tracking.general.descriptionTextModule',
});
