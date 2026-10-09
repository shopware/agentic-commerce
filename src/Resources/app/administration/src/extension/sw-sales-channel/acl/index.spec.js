/**
 * @sw-package discovery
 *
 * The Agentic Commerce tab shows on `ucp.viewer`, edits on `ucp.editor` and rotates signing keys on
 * `ucp.key_rotator`; the sales channel roles add what the tracking statistics and config need.
 */

describe('extension/sw-sales-channel/acl', () => {
    let entries;

    beforeEach(() => {
        const addPrivilegeMappingEntry = jest.fn();
        global.Shopware = { Service: (name) => ({ privileges: { addPrivilegeMappingEntry } })[name] };

        require('./index');

        entries = Object.fromEntries(addPrivilegeMappingEntry.mock.calls.map(([entry]) => [entry.key, entry]));
    });

    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    function privileges(role) {
        return [...role.privileges].sort();
    }

    it('registers top-level permission entries for sales channels and UCP', () => {
        expect(Object.keys(entries).sort()).toEqual(['sales_channel', 'ucp']);
        Object.values(entries).forEach((entry) => {
            expect(entry).toMatchObject({ category: 'permissions', parent: null });
        });
    });

    it('lets UCP viewers read the configuration of a sales channel and its domains', () => {
        expect(privileges(entries.ucp.roles.viewer)).toEqual(['sales_channel:read', 'sales_channel_domain:read', 'system_config:read']);
        expect(entries.ucp.roles.viewer.dependencies).toEqual([]);
    });

    it('builds the UCP editor and key rotator roles on top of the viewer', () => {
        expect(Object.keys(entries.ucp.roles).sort()).toEqual(['editor', 'key_rotator', 'viewer']);
        expect(privileges(entries.ucp.roles.editor)).toEqual(['system_config:update']);
        expect(entries.ucp.roles.editor.dependencies).toEqual(['ucp.viewer']);

        expect(privileges(entries.ucp.roles.key_rotator)).toEqual([]);
        expect(entries.ucp.roles.key_rotator.dependencies).toEqual(['ucp.viewer']);
    });

    it('lets sales channel viewers read the tracked orders and customers behind the statistics', () => {
        expect(privileges(entries.sales_channel.roles.viewer)).toEqual([
            'order:read',
            'order_transaction:read',
            'sales_channel_tracking_customer:read',
            'sales_channel_tracking_order:read',
            'state_machine_state:read',
            'system_config:read',
        ]);
    });

    it('gives sales channel editors and creators their additional privileges', () => {
        expect(privileges(entries.sales_channel.roles.editor)).toEqual([
            'property_group:read',
            'system_config:create',
            'system_config:delete',
            'system_config:update',
        ]);
        expect(privileges(entries.sales_channel.roles.creator)).toEqual(['property_group:read']);
    });
});