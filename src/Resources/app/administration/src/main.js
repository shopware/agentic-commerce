// Imported first — probe reads core's state before plugin extensions register.
import { coreShipsAgenticCommerce } from './core-feature';

import './init/defaults.init';
import './core/service/api/ucp-admin.api.service.js';
import './module/sw-settings-ucp/index.js';
import './extension/sw-customer/acl';
import './extension/sw-sales-channel';

// Core ships the list-column tracking module natively from 6.7.10+.
if (!coreShipsAgenticCommerce) {
    import('./module/sw-export-channel-tracking');
}
