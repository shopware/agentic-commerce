export const capabilityOptions = [
    { value: 'catalog', label: 'sw-settings-ucp.general.capabilityCatalogLabel' },
    { value: 'cart', label: 'sw-settings-ucp.general.capabilityCartLabel' },
    { value: 'discount', label: 'sw-settings-ucp.general.capabilityDiscountLabel' },
    { value: 'checkout', label: 'sw-settings-ucp.general.capabilityCheckoutLabel' },
    { value: 'order', label: 'sw-settings-ucp.general.capabilityOrderLabel' },
    { value: 'identity_linking', label: 'sw-settings-ucp.general.capabilityIdentityLinkingLabel' },
    { value: 'payment_tokenization', label: 'sw-settings-ucp.general.capabilityPaymentTokenizationLabel' },
];

export const transportOptions = [
    { value: 'rest', label: 'REST', description: 'sw-settings-ucp.general.transportRestDescription' },
    { value: 'a2a', label: 'A2A', description: 'sw-settings-ucp.general.transportA2aDescription' },
    { value: 'embedded', label: 'Embedded', description: 'sw-settings-ucp.general.transportEmbeddedDescription' },
    {
        value: 'mcp',
        label: 'MCP',
        description: 'sw-settings-ucp.general.transportMcpDescription',
        requiresStoreApiMcp: true,
        disabledReason: 'sw-settings-ucp.general.transportMcpDisabledReason',
    },
];

export const signaturePolicyOptions = [
    { value: 'strict', label: 'sw-settings-ucp.general.signaturePolicyStrictLabel' },
    { value: 'log', label: 'sw-settings-ucp.general.signaturePolicyLogLabel' },
    { value: 'off', label: 'sw-settings-ucp.general.signaturePolicyOffLabel' },
];

export const profileUriStrategyOptions = [
    { value: 'domain', label: 'sw-settings-ucp.general.profileUriStrategyDomainLabel' },
    { value: 'config', label: 'sw-settings-ucp.general.profileUriStrategyConfigLabel' },
];

export const keyAlgorithmOptions = [
    { value: 'ES256', label: 'ES256' },
    { value: 'ES384', label: 'ES384' },
];
