/**
 * @sw-package discovery
 *
 * The customer list shows which export channel a customer came from; viewers need to read that.
 */

describe('extension/sw-customer/acl', () => {
    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    it('lets customer viewers read the sales channel tracking of a customer', () => {
        const addPrivilegeMappingEntry = jest.fn();
        global.Shopware = { Service: (name) => ({ privileges: { addPrivilegeMappingEntry } })[name] };

        require('./index');

        expect(addPrivilegeMappingEntry).toHaveBeenCalledTimes(1);
        expect(addPrivilegeMappingEntry).toHaveBeenCalledWith({
            category: 'permissions',
            parent: null,
            key: 'customer',
            roles: {
                viewer: {
                    privileges: ['sales_channel_tracking_customer:read'],
                    dependencies: [],
                },
            },
        });
    });
});