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
        // MCP server (6.7+). On older lanes it is surfaced in the "Not yet
        // available" group instead — see availableTransports / notReadyTransports.
        value: 'mcp',
        label: 'MCP',
        description: 'sw-sales-channel.detail.agenticCommerce.ucp.transportMcpDescription',
        requiresStoreApiMcp: true,
        reason: 'sw-sales-channel.detail.agenticCommerce.ucp.transportMcpDisabledReason',
        docsUrl: 'https://developer.shopware.com/docs/concepts/agentic-commerce/ucp.html',
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
 * Transports that exist in the protocol but are not usable on this platform yet
 * (currently just MCP on pre-6.7 lanes) — shown read-only in "Not yet available".
 *
 * @param {{ supportsStoreApiMcp?: boolean }} meta
 */
export function notReadyTransports(meta = {}) {
    return transportOptions.filter((transport) => transport.requiresStoreApiMcp && meta.supportsStoreApiMcp !== true);
}

export const signaturePolicyOptions = [
    { value: 'strict', label: 'sw-sales-channel.detail.agenticCommerce.ucp.signaturePolicyStrictLabel' },
    { value: 'log', label: 'sw-sales-channel.detail.agenticCommerce.ucp.signaturePolicyLogLabel' },
    { value: 'off', label: 'sw-sales-channel.detail.agenticCommerce.ucp.signaturePolicyOffLabel' },
];

export const keyAlgorithmOptions = [
    { value: 'ES256', label: 'ES256' },
    { value: 'ES384', label: 'ES384' },
];

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
