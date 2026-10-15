/**
 * Static option lists for the UCP form. Labels are snippet keys resolved by the
 * component with `$t`. `profileUriStrategyOptions` was removed (redesign §10.5).
 */

export const transportOptions = [
    { value: 'rest', label: 'REST', description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportRestDescription' },
    { value: 'a2a', label: 'A2A', description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportA2aDescription' },
    { value: 'embedded', label: 'Embedded', description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportEmbeddedDescription' },
    {
        // MCP is selectable only where the Admin API reports a Store-API MCP
        // server (`meta.supportsStoreApiMcp`); availableTransports() filters it out otherwise.
        value: 'mcp',
        label: 'MCP',
        description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportMcpDescription',
        requiresStoreApiMcp: true,
    },
];

/**
 * Transports the merchant can toggle on the given platform. MCP appears only
 * when the Admin API reports a Store-API MCP server (`meta.supportsStoreApiMcp`).
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
