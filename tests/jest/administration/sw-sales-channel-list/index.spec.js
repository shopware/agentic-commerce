global.Shopware = {
    Component: { override: jest.fn() },
    Service: jest.fn(),
};

const { swSalesChannelListOverride } = require('Resources/extension/sw-sales-channel/page/sw-sales-channel-list');

const { canViewUcpStatus, salesChannelColumns } = swSalesChannelListOverride.computed;
const { loadUcpActiveStates } = swSalesChannelListOverride.methods;

describe('sw-sales-channel-list UCP status column', () => {
    it('does not add the UCP column without ucp.viewer', () => {
        const context = {
            acl: { can: jest.fn(() => false) },
            $super: jest.fn(() => [{ property: 'name' }, { property: 'status' }]),
        };

        expect(canViewUcpStatus.call(context)).toBe(false);
        context.canViewUcpStatus = false;

        expect(salesChannelColumns.call(context)).toEqual([{ property: 'name' }, { property: 'status' }]);
        expect(context.acl.can).toHaveBeenCalledWith('ucp.viewer');
    });

    it('adds the UCP column when ucp.viewer is granted', () => {
        const context = {
            acl: { can: jest.fn(() => true) },
            $super: jest.fn(() => [{ property: 'name' }, { property: 'status' }]),
        };

        expect(canViewUcpStatus.call(context)).toBe(true);
        context.canViewUcpStatus = true;

        expect(salesChannelColumns.call(context).map((column) => column.property)).toEqual(['name', 'status', 'ucpActive']);
    });

    it('does not call the UCP API without ucp.viewer', () => {
        const context = {
            canViewUcpStatus: false,
        };

        loadUcpActiveStates.call(context);

        expect(Shopware.Service).not.toHaveBeenCalled();
    });

    it('loads active states through the UCP API when ucp.viewer is granted', async () => {
        const getSalesChannels = jest.fn(() => Promise.resolve({
            data: {
                data: [
                    { id: 'active-channel', ucp: { active: true } },
                    { id: 'inactive-channel', ucp: { active: false } },
                ],
            },
        }));
        Shopware.Service.mockReturnValue({ getSalesChannels });
        const context = {
            canViewUcpStatus: true,
            ucpActiveMap: {},
        };

        loadUcpActiveStates.call(context);
        await Promise.resolve();

        expect(Shopware.Service).toHaveBeenCalledWith('ucpAdminApiService');
        expect(context.ucpActiveMap).toEqual({
            'active-channel': true,
            'inactive-channel': false,
        });
    });
});
