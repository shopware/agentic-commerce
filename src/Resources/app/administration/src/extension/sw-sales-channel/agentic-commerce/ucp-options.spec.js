import { availableTransports } from './ucp-options';

const values = (transports) => transports.map((transport) => transport.value);

describe('agentic-commerce/ucp-options availableTransports', () => {
    it('offers MCP when the Admin API reports Store-API MCP support, whatever the version', () => {
        expect(values(availableTransports({ supportsStoreApiMcp: true, shopwareVersion: '6.5.8.0' })))
            .toEqual(['rest', 'a2a', 'embedded', 'mcp']);
    });

    it('hides MCP when the Admin API reports no Store-API MCP support, whatever the version', () => {
        expect(values(availableTransports({ supportsStoreApiMcp: false, shopwareVersion: '6.7.10.0' })))
            .toEqual(['rest', 'a2a', 'embedded']);
    });

    it('hides MCP when the flag is missing', () => {
        expect(values(availableTransports())).toEqual(['rest', 'a2a', 'embedded']);
    });
});