Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'sales_channel',
    roles: {
        viewer: {
            privileges: [
                'system_config:read',
                'sales_channel_tracking_order:read',
                'sales_channel_tracking_customer:read',
                'order:read',
                'order_transaction:read',
                'state_machine_state:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'system_config:update',
                'system_config:create',
                'system_config:delete',
                'property_group:read',
            ],
            dependencies: [],
        },
        creator: {
            privileges: [
                'property_group:read',
            ],
            dependencies: [],
        },
    },
});

// UCP config privileges. `ucp.key_rotator` currently adds only the viewer
// dependency; signing keys are managed through console commands, not the UI.
// The parent is null since UCP config lives on the sales channel.
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'ucp',
    roles: {
        viewer: {
            privileges: [
                'system_config:read',
                'sales_channel:read',
                'sales_channel_domain:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'system_config:update',
            ],
            dependencies: [
                'ucp.viewer',
            ],
        },
        key_rotator: {
            privileges: [],
            dependencies: [
                'ucp.viewer',
            ],
        },
    },
});
