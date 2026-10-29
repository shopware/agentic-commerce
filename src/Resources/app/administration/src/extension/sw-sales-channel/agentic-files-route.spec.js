import { getAgenticFileDetailRoute, getAgenticFileListRoute } from './agentic-files-route';

describe('extension/sw-sales-channel Agentic Files routes', () => {
    const salesChannelId = 'sales-channel-id';
    const file = { fileName: 'llms.txt' };
    const fileDetailRoute = {
        name: 'sw.sales.channel.detail.agenticFile',
        params: { id: salesChannelId, fileName: file.fileName },
    };

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
            if (componentName === 'sw-sales-channel-detail-agentic-files') {
                require('./view/sw-sales-channel-detail-agentic-files');
                return;
            }

            require('./view/sw-sales-channel-detail-agentic-file');
        });

        return {
            Component,
            config: Component.override.mock.calls[0]?.[1],
        };
    }

    it('marks file links opened from the Agentic Commerce tab', () => {
        const route = getAgenticFileDetailRoute({ name: 'sw.sales.channel.detail.agenticCommerce' }, fileDetailRoute);

        expect(route).toEqual({
            ...fileDetailRoute,
            query: { swagAgenticCommerceFrom: 'agentic-commerce' },
        });
        expect(route.params).toEqual({ id: salesChannelId, fileName: file.fileName });
    });

    it('leaves file links from the Agentic Files tab unchanged', () => {
        const route = getAgenticFileDetailRoute({ name: 'sw.sales.channel.detail.agenticFiles' }, fileDetailRoute);

        expect(route).toBe(fileDetailRoute);
    });

    it('returns to Agentic Commerce when the file was opened from its embedded list', () => {
        const route = getAgenticFileListRoute({
            name: 'sw.sales.channel.detail.agenticFile',
            query: { swagAgenticCommerceFrom: 'agentic-commerce' },
        }, salesChannelId);

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticCommerce',
            params: { id: salesChannelId },
        });
    });

    it('returns to Agentic Files for a direct or unmarked detail route', () => {
        const route = getAgenticFileListRoute({ name: 'sw.sales.channel.detail.agenticFile', query: {} }, salesChannelId);

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticFiles',
            params: { id: salesChannelId },
        });
    });

    it('adds the origin marker only to file detail links from the Agentic Commerce tab', () => {
        const { config } = loadOverride('sw-sales-channel-detail-agentic-files');
        const superRoute = jest.fn(() => fileDetailRoute);

        const route = config.methods.getDetailRoute.call({
            $route: { name: 'sw.sales.channel.detail.agenticCommerce' },
            $super: superRoute,
        }, file);

        expect(superRoute).toHaveBeenCalledWith('getDetailRoute', file);
        expect(route).toEqual({
            ...fileDetailRoute,
            query: { swagAgenticCommerceFrom: 'agentic-commerce' },
        });
    });

    it('keeps file detail links from Agentic Files unchanged', () => {
        const { config } = loadOverride('sw-sales-channel-detail-agentic-files');
        const superRoute = jest.fn(() => fileDetailRoute);

        const route = config.methods.getDetailRoute.call({
            $route: { name: 'sw.sales.channel.detail.agenticFiles' },
            $super: superRoute,
        }, file);

        expect(route).toBe(fileDetailRoute);
    });

    it('returns to the embedded source from the overridden file-detail component', () => {
        const { config } = loadOverride('sw-sales-channel-detail-agentic-file');
        const route = config.computed.listRoute.call({
            $route: {
                params: { id: salesChannelId },
                query: { swagAgenticCommerceFrom: 'agentic-commerce' },
            },
        });

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticCommerce',
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
        });

        expect(route).toEqual({
            name: 'sw.sales.channel.detail.agenticFiles',
            params: { id: salesChannelId },
        });
    });

    it('does not register an override when the file list component is unavailable', () => {
        const { Component } = loadOverride('sw-sales-channel-detail-agentic-files', false);

        expect(Component.override).not.toHaveBeenCalled();
    });

    it('does not register an override when the file detail component is unavailable', () => {
        const { Component } = loadOverride('sw-sales-channel-detail-agentic-file', false);

        expect(Component.override).not.toHaveBeenCalled();
    });
});
