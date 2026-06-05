Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'customer',
    roles: {
        viewer: {
            privileges: [
                'sales_channel_tracking_customer:read',
            ],
            dependencies: [],
        },
    },
});