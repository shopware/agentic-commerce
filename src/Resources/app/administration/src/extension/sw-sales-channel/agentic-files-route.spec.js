import { getAgenticFileListRoute } from './agentic-files-route';

describe('extension/sw-sales-channel Agentic Files routes', () => {
    const salesChannelId = 'sales-channel-id';
    afterEach(() => {
        delete global.Shopware;
        jest.resetModules();
    });

    function loadOverride(componentName, componentAvailable = true) {
        const Component = {
            getComponentRegistry: () => new Map(componentAvailable ? [[componentName, {}]] : []),
            override: jest.fn(),
        };

        global.Shopware = { Component };

        jest.isolateModules(() => {
            require('./view/sw-sales-channel-detail-agentic-file');
        });

        return {
            Component,
            config: Component.override.mock.calls[0]?.[1],
        };
    }

    it('returns to Agentic Commerce for a direct detail route when its tab is available', () => {
        const route = getAgenticFileListRoute(salesChannelId, true);

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticCommerce',
            params: { id: salesChannelId },
        });
    });

    it('returns to Agentic Files for a direct detail route when its tab is unavailable', () => {
        const route = getAgenticFileListRoute(salesChannelId, false);

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticFiles',
            params: { id: salesChannelId },
        });
    });

    it('returns to Agentic Files from a marked detail route when its tab is unavailable', () => {
        const { config } = loadOverride('sw-sales-channel-detail-agentic-file');
        const route = config.computed.listRoute.call({
            $route: {
                params: { id: salesChannelId },
                query: { swagAgenticCommerceFrom: 'agentic-commerce' },
            },
            swSalesChannelShouldRenderAgenticCommerceTab: () => false,
        });

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticFiles',
            params: { id: salesChannelId },
        });
    });

    it('preserves the core destination from the overridden file-detail component for unmarked routes', () => {
        const { config } = loadOverride('sw-sales-channel-detail-agentic-file');
        const route = config.computed.listRoute.call({
            $route: {
                params: { id: salesChannelId },
                query: {},
            },
            swSalesChannelShouldRenderAgenticCommerceTab: () => false,
        });

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticFiles',
            params: { id: salesChannelId },
        });
    });

    it('returns to Agentic Commerce from a direct detail route when its tab is available', () => {
        const { config } = loadOverride('sw-sales-channel-detail-agentic-file');
        const route = config.computed.listRoute.call({
            $route: {
                params: { id: salesChannelId },
                query: {},
            },
            swSalesChannelShouldRenderAgenticCommerceTab: () => true,
        });

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticCommerce',
            params: { id: salesChannelId },
        });
    });

    it('does not register an override when the file detail component is unavailable', () => {
        const { Component } = loadOverride('sw-sales-channel-detail-agentic-file', false);

        expect(Component.override).not.toHaveBeenCalled();
    });
});
