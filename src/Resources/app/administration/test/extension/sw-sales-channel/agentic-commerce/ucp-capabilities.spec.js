import {
    READY_CAPABILITIES,
    NOT_READY_CAPABILITIES,
    readyCapabilityValues,
    notReadyCapabilityValues,
    isReadyCapability,
} from '../../../../../src/extension/sw-sales-channel/agentic-commerce/ucp-capabilities';

describe('agentic-commerce/ucp-capabilities', () => {
    it('lists the 5 production-ready capabilities', () => {
        expect(readyCapabilityValues()).toEqual(['catalog', 'cart', 'discount', 'checkout', 'order']);
    });

    it('keeps identity_linking and payment_tokenization out of Basic (not-ready)', () => {
        expect(notReadyCapabilityValues()).toEqual(['identity_linking', 'payment_tokenization']);
        expect(isReadyCapability('identity_linking')).toBe(false);
        expect(isReadyCapability('catalog')).toBe(true);
    });

    it('gives every ready capability a tooltip and every not-ready one a reason + docs link', () => {
        READY_CAPABILITIES.forEach((capability) => {
            expect(typeof capability.tooltip).toBe('string');
            expect(capability.tooltip.length).toBeGreaterThan(0);
        });
        NOT_READY_CAPABILITIES.forEach((capability) => {
            expect(typeof capability.reason).toBe('string');
            expect(capability.docsUrl).toMatch(/^https?:\/\//);
        });
    });
});
