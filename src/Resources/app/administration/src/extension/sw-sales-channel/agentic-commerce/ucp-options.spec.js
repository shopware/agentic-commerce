/**
 * @sw-package discovery
 *
 * MCP is only a real transport where the Store API ships an MCP server (6.7.12+ with the
 * MCP_SERVER feature flag); older platforms must not offer it.
 */

import { availableTransports, isTransportUnsupported, transportOptions } from './ucp-options';

describe('agentic-commerce/ucp-options', () => {
    it.each([
        ['the platform meta is missing', undefined],
        ['the platform has no Store API MCP server', { supportsStoreApiMcp: false }],
        ['the platform has no Store API MCP server, whatever the version', { supportsStoreApiMcp: false, shopwareVersion: '6.7.10.0' }],
    ])('offers REST, A2A and embedded but not MCP when %s', (_, meta) => {
        expect(availableTransports(meta).map((transport) => transport.value)).toEqual(['rest', 'a2a', 'embedded']);
    });

    it.each([
        ['the platform has a Store API MCP server', { supportsStoreApiMcp: true }],
        ['the platform has a Store API MCP server, whatever the version', { supportsStoreApiMcp: true, shopwareVersion: '6.5.8.0' }],
    ])('offers MCP when %s', (_, meta) => {
        expect(availableTransports(meta).map((transport) => transport.value)).toEqual(['rest', 'a2a', 'embedded', 'mcp']);
    });

    it('marks only MCP as unsupported on a platform without a Store API MCP server', () => {
        const unsupported = transportOptions.filter((transport) => isTransportUnsupported(transport, { supportsStoreApiMcp: false }));

        expect(unsupported.map((transport) => transport.value)).toEqual(['mcp']);
        expect(transportOptions.some((transport) => isTransportUnsupported(transport, { supportsStoreApiMcp: true }))).toBe(false);
    });
});