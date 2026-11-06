import {
    defaultForm,
    normalizeConfig,
    buildConfigPayload,
    toggleArrayValue,
    normalizeHostEntry,
    isValidHostEntry,
    normalizeHostList,
} from './ucp-form-state';

describe('agentic-commerce/ucp-form-state', () => {
    describe('defaultForm', () => {
        it('omits the removed profileUriStrategy/customProfileUri fields (§10.5)', () => {
            const form = defaultForm();
            expect('profileUriStrategy' in form).toBe(false);
            expect('customProfileUri' in form).toBe(false);
        });

        it('defaults to the Exposure-tab surface only', () => {
            const form = defaultForm();
            expect(form.enabledCapabilities).toEqual(['catalog', 'cart', 'discount', 'checkout', 'order']);
            expect(form.enabledTransports).toEqual(['rest']);
            expect(form.active).toBe(false);
            expect(form.platformAllowlist).toEqual([]);
            // Security/Advanced fields are managed via console commands, not the form.
            expect('signaturePolicy' in form).toBe(false);
            expect('agentAllowlist' in form).toBe(false);
        });
    });

    describe('normalizeConfig', () => {
        it('drops unknown / non-Exposure keys (managed via console)', () => {
            const result = normalizeConfig({ signaturePolicy: 'log', agentAllowlist: ['x'] });
            expect('signaturePolicy' in result).toBe(false);
            expect('agentAllowlist' in result).toBe(false);
        });

        it('keeps known values and coerces bad arrays back to defaults', () => {
            const result = normalizeConfig({ profileDomain: 'https://shop.example', enabledTransports: 'nope' });
            expect(result.profileDomain).toBe('https://shop.example');
            expect(result.enabledTransports).toEqual(['rest']);
        });

        it('keeps the stored platform allowlist', () => {
            expect(normalizeConfig({ platformAllowlist: ['platform.example'] }).platformAllowlist)
                .toEqual(['platform.example']);
        });

        it('falls back to an empty platform allowlist for configs saved before the field existed', () => {
            expect(normalizeConfig({ active: true }).platformAllowlist).toEqual([]);
        });

        it('coerces empty nullable strings to null', () => {
            expect(normalizeConfig({ profileDomain: '' }).profileDomain).toBeNull();
        });
    });

    describe('buildConfigPayload', () => {
        it('clones array fields so component state cannot be mutated', () => {
            const form = defaultForm();
            const payload = buildConfigPayload(form);
            payload.enabledCapabilities.push('extra');
            expect(form.enabledCapabilities).toHaveLength(5);
        });

        it('sends the platform allowlist so the server stores it', () => {
            const form = { ...defaultForm(), platformAllowlist: ['platform.example'] };
            const payload = buildConfigPayload(form);
            payload.platformAllowlist.push('other.example');
            expect(form.platformAllowlist).toEqual(['platform.example']);
        });
    });

    describe('normalizeHostEntry', () => {
        it('cuts a pasted profile URL down to its host', () => {
            expect(normalizeHostEntry('https://Platform.Example/.well-known/ucp')).toBe('platform.example');
        });

        it('lower-cases and trims a bare host', () => {
            expect(normalizeHostEntry('  Agent.Example.  ')).toBe('agent.example');
        });
    });

    describe('isValidHostEntry', () => {
        it('accepts host names, profile URLs and IPv4 addresses', () => {
            expect(isValidHostEntry('platform.example')).toBe(true);
            expect(isValidHostEntry('https://platform.example/.well-known/ucp')).toBe(true);
            expect(isValidHostEntry('192.0.2.10')).toBe(true);
        });

        it('rejects entries the server would refuse', () => {
            expect(isValidHostEntry('')).toBe(false);
            expect(isValidHostEntry('platform example')).toBe(false);
            expect(isValidHostEntry('platform.example/path')).toBe(false);
            expect(isValidHostEntry('-platform.example')).toBe(false);
            expect(isValidHostEntry('com')).toBe(false);
        });
    });

    describe('normalizeHostList', () => {
        it('normalizes and removes duplicates', () => {
            expect(normalizeHostList(['https://platform.example/x', 'PLATFORM.example', ' ', 'agent.example']))
                .toEqual(['platform.example', 'agent.example']);
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
