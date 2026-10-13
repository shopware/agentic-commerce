/**
 * @sw-package discovery
 *
 * The tab offers MCP when the Admin API reports Store-API MCP support
 * (`meta.supportsStoreApiMcp`), whatever the running Shopware version says. A version
 * check in the component would agree with the flag on most lanes, so each case pairs
 * the flag with a version that contradicts it.
 */
const register = jest.fn();

global.Shopware = {
    ...global.Shopware,
    Component: {
        getComponentRegistry: () => new Map(),
        register,
        override: jest.fn(),
    },
    Context: { app: { config: { version: '' } } },
};

require('./index');

const { transportItems } = register.mock.calls[0][1].computed;

function offeredTransports(adminVersion, meta) {
    global.Shopware.Context.app.config.version = adminVersion;

    return transportItems.call({ meta }).map((transport) => transport.value);
}

describe('sw-sales-channel-detail-agentic-commerce transports', () => {
    it('offers MCP on an older version when the Admin API reports support', () => {
        expect(offeredTransports('6.5.8.0', { supportsStoreApiMcp: true })).toContain('mcp');
    });

    it('hides MCP on a current version when the Admin API reports no support', () => {
        expect(offeredTransports('6.7.10.0', { supportsStoreApiMcp: false })).not.toContain('mcp');
    });
});