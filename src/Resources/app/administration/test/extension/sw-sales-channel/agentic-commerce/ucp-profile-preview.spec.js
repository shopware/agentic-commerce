import {
    buildPreviewPayload,
    isPreviewDirty,
    extractProfileMetadata,
    profileCapabilityNames,
    serviceEndpointCount,
} from '../../../../src/extension/sw-sales-channel/agentic-commerce/ucp-profile-preview';
import { defaultForm } from '../../../../src/extension/sw-sales-channel/agentic-commerce/ucp-form-state';

describe('agentic-commerce/ucp-profile-preview', () => {
    describe('buildPreviewPayload', () => {
        it('wraps the config payload and selected profile domain', () => {
            const payload = buildPreviewPayload(defaultForm(), { profileDomain: 'https://shop.example' });
            expect(payload.profileDomain).toBe('https://shop.example');
            expect(payload.config.signaturePolicy).toBe('strict');
        });
        it('defaults the profile domain to null', () => {
            expect(buildPreviewPayload(defaultForm()).profileDomain).toBeNull();
        });
    });

    describe('isPreviewDirty', () => {
        it('is false for key-order-only differences', () => {
            expect(isPreviewDirty({ a: 1, b: 2 }, { b: 2, a: 1 })).toBe(false);
        });
        it('is true when a value changes', () => {
            expect(isPreviewDirty({ a: 1 }, { a: 2 })).toBe(true);
        });
    });

    describe('profile metadata helpers', () => {
        it('reads the ucp block or the preview itself', () => {
            expect(extractProfileMetadata({ ucp: { x: 1 } })).toEqual({ x: 1 });
            expect(extractProfileMetadata({ x: 1 })).toEqual({ x: 1 });
            expect(extractProfileMetadata(null)).toEqual({});
        });
        it('counts capability names', () => {
            expect(profileCapabilityNames({ ucp: { capabilities: { catalog: {}, cart: {} } } })).toEqual(['catalog', 'cart']);
            expect(profileCapabilityNames(null)).toEqual([]);
        });
        it('sums endpoints across services', () => {
            expect(serviceEndpointCount({ ucp: { services: { s1: [1, 2], s2: [3] } } })).toBe(3);
            expect(serviceEndpointCount({})).toBe(0);
        });
    });
});
