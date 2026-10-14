/**
 * @sw-package discovery
 *
 * From 6.7.10 core registers some of the plugin's components itself, and a second `register` is
 * rejected with a warning, so the plugin has to override them there instead.
 */

import { registerOrOverride } from './register-or-override';

describe('helper/register-or-override', () => {
    afterEach(() => {
        delete global.Shopware;
    });

    function setUpRegistry(registeredNames) {
        global.Shopware = {
            Component: {
                getComponentRegistry: () => new Map(registeredNames.map((name) => [name, {}])),
                register: jest.fn(),
                override: jest.fn(),
            },
        };

        return global.Shopware.Component;
    }

    it('registers the component when core does not ship it', () => {
        const Component = setUpRegistry([]);
        const config = { template: 'plugin-template' };

        registerOrOverride('sw-sales-channel-detail-agentic-commerce-statistics', config);

        expect(Component.register).toHaveBeenCalledWith('sw-sales-channel-detail-agentic-commerce-statistics', config);
        expect(Component.override).not.toHaveBeenCalled();
    });

    it('overrides the component when core already registered it', () => {
        const Component = setUpRegistry(['sw-sales-channel-detail-agentic-commerce-integration']);
        const config = { template: 'plugin-template' };

        registerOrOverride('sw-sales-channel-detail-agentic-commerce-integration', config);

        expect(Component.override).toHaveBeenCalledWith('sw-sales-channel-detail-agentic-commerce-integration', config);
        expect(Component.register).not.toHaveBeenCalled();
    });
});