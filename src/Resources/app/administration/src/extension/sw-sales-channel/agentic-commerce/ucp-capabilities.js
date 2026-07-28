/**
 * Capability metadata for the UCP card.
 *
 * Basic UI shows only the production-ready capabilities (each with a one-line
 * tooltip). The not-ready ones are not shown in Basic at all — they live in the
 * collapsed "Not yet available" group in Developer & Advanced, each with a
 * reason and a docs link (redesign §6).
 */

const SNIPPET_ROOT = 'sw-sales-channel.detail.agenticCommerce.ucp';

export const READY_CAPABILITIES = [
    { value: 'catalog', label: `${SNIPPET_ROOT}.capabilityCatalogLabel`, tooltip: `${SNIPPET_ROOT}.capabilityCatalogTooltip` },
    { value: 'cart', label: `${SNIPPET_ROOT}.capabilityCartLabel`, tooltip: `${SNIPPET_ROOT}.capabilityCartTooltip` },
    { value: 'discount', label: `${SNIPPET_ROOT}.capabilityDiscountLabel`, tooltip: `${SNIPPET_ROOT}.capabilityDiscountTooltip` },
    { value: 'checkout', label: `${SNIPPET_ROOT}.capabilityCheckoutLabel`, tooltip: `${SNIPPET_ROOT}.capabilityCheckoutTooltip` },
    { value: 'order', label: `${SNIPPET_ROOT}.capabilityOrderLabel`, tooltip: `${SNIPPET_ROOT}.capabilityOrderTooltip` },
    { value: 'identity_linking', label: `${SNIPPET_ROOT}.capabilityIdentityLinkingLabel`, tooltip: `${SNIPPET_ROOT}.capabilityIdentityLinkingTooltip` },
    { value: 'quote', label: `${SNIPPET_ROOT}.capabilityQuoteLabel`, tooltip: `${SNIPPET_ROOT}.capabilityQuoteTooltip` },
];

export const NOT_READY_CAPABILITIES = [
    {
        value: 'payment_tokenization',
        label: `${SNIPPET_ROOT}.capabilityPaymentTokenizationLabel`,
        reason: `${SNIPPET_ROOT}.capabilityPaymentTokenizationReason`,
        docsUrl: 'https://developer.shopware.com/docs/concepts/agentic-commerce/ucp.html',
    },
];

export function readyCapabilityValues() {
    return READY_CAPABILITIES.map((capability) => capability.value);
}

export function notReadyCapabilityValues() {
    return NOT_READY_CAPABILITIES.map((capability) => capability.value);
}

/**
 * @param {string} value
 * @returns {boolean}
 */
export function isReadyCapability(value) {
    return readyCapabilityValues().includes(value);
}
