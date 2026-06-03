Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'settings',
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
