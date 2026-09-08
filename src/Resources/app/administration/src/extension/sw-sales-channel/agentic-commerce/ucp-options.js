/**
 * Static option lists for the UCP form. Labels are snippet keys resolved by the
 * component with `$t`. `profileUriStrategyOptions` was removed (redesign §10.5).
 */

export const transportOptions = [
    { value: 'rest', label: 'REST', description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportRestDescription' },
    { value: 'a2a', label: 'A2A', description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportA2aDescription' },
    { value: 'embedded', label: 'Embedded', description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportEmbeddedDescription' },
    {
        // MCP is selectable only on Shopware versions whose Store-API exposes an
        // MCP server (6.7+). On older lanes availableTransports() filters it out.
        value: 'mcp',
        label: 'MCP',
        description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportMcpDescription',
        requiresStoreApiMcp: true,
    },
];

/**
 * Transports the merchant can toggle on the given platform. MCP appears only
 * when the Store-API MCP server is available (6.7+).
 *
 * @param {{ supportsStoreApiMcp?: boolean }} meta
 */
export function availableTransports(meta = {}) {
    return transportOptions.filter((transport) => !transport.requiresStoreApiMcp || meta.supportsStoreApiMcp === true);
}

/**
 * Whether a transport option is unsupported on the running platform
 * (e.g. MCP needs Store-API MCP support, surfaced via the sales-channel meta).
 *
 * @param {{ requiresStoreApiMcp?: boolean }} transport
 * @param {{ supportsStoreApiMcp?: boolean }} meta
 */
export function isTransportUnsupported(transport, meta = {}) {
    return transport.requiresStoreApiMcp === true && meta.supportsStoreApiMcp !== true;
}
