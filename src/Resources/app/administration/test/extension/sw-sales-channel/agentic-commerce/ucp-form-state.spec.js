import {
    defaultForm,
    normalizeConfig,
    buildConfigPayload,
    toggleArrayValue,
} from '../../../../src/extension/sw-sales-channel/agentic-commerce/ucp-form-state';

describe('agentic-commerce/ucp-form-state', () => {
    describe('defaultForm', () => {
        it('omits the removed profileUriStrategy/customProfileUri fields (§10.5)', () => {
            const form = defaultForm();
            expect('profileUriStrategy' in form).toBe(false);
            expect('customProfileUri' in form).toBe(false);
        });

        it('defaults to a secure, ready configuration', () => {
            const form = defaultForm();
            expect(form.signaturePolicy).toBe('strict');
            expect(form.idempotencyRequired).toBe(true);
            expect(form.enabledCapabilities).toEqual(['catalog', 'cart', 'discount', 'checkout', 'order']);
            expect(form.enabledTransports).toEqual(['rest']);
            expect(form.active).toBe(false);
        });
    });

    describe('normalizeConfig', () => {
        it('drops unknown/legacy keys', () => {
            const result = normalizeConfig({ profileUriStrategy: 'config', customProfileUri: 'https://x' });
            expect('profileUriStrategy' in result).toBe(false);
            expect('customProfileUri' in result).toBe(false);
        });

        it('keeps known values and coerces bad arrays back to defaults', () => {
            const result = normalizeConfig({ signaturePolicy: 'log', enabledTransports: 'nope' });
            expect(result.signaturePolicy).toBe('log');
            expect(result.enabledTransports).toEqual(['rest']);
        });

        it('coerces empty nullable strings to null', () => {
            expect(normalizeConfig({ continueUrlTemplate: '' }).continueUrlTemplate).toBeNull();
        });
    });

    describe('buildConfigPayload', () => {
        it('clones array fields so component state cannot be mutated', () => {
            const form = defaultForm();
            const payload = buildConfigPayload(form);
            payload.enabledCapabilities.push('extra');
            expect(form.enabledCapabilities).toHaveLength(5);
        });
    });

    describe('toggleArrayValue', () => {
        it('adds when enabled and not present', () => {
            expect(toggleArrayValue(['a'], 'b', true)).toEqual(['a', 'b']);
        });
        it('removes when disabled and present', () => {
            expect(toggleArrayValue(['a', 'b'], 'b', false)).toEqual(['a']);
        });
        it('is a no-op when already in desired state', () => {
            expect(toggleArrayValue(['a'], 'a', true)).toEqual(['a']);
            expect(toggleArrayValue(['a'], 'b', false)).toEqual(['a']);
        });
    });
});
