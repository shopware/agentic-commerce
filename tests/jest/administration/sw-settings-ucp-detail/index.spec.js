import component from 'Resources/module/sw-settings-ucp/page/sw-settings-ucp-detail/index.js';
import { defaultForm } from 'Resources/module/sw-settings-ucp/page/sw-settings-ucp-detail/form-state.js';

describe('sw-settings-ucp-detail component config behavior', () => {
    it('builds a domain-strategy payload without a stale custom profile URI', () => {
        const context = createContext({
            form: {
                profileUriStrategy: 'domain',
                customProfileUri: 'https://stale.example/ucp',
                enabledCapabilities: ['catalog'],
                enabledTransports: ['rest'],
                remoteProfileAllowlist: ['platform.example'],
            },
        });

        const payload = component.methods.buildPayload.call(context);

        expect(payload.customProfileUri).toBeNull();
        expect(payload.enabledCapabilities).toEqual(['catalog']);
        expect(payload.enabledCapabilities).not.toBe(context.form.enabledCapabilities);
        expect(payload.enabledTransports).not.toBe(context.form.enabledTransports);
        expect(payload.remoteProfileAllowlist).not.toBe(context.form.remoteProfileAllowlist);
    });

    it('builds a config-strategy payload with the configured profile URI', () => {
        const context = createContext({
            form: {
                profileUriStrategy: 'config',
                customProfileUri: 'https://merchant.example/custom-ucp',
            },
        });

        expect(component.methods.buildPayload.call(context)).toEqual(expect.objectContaining({
            profileUriStrategy: 'config',
            customProfileUri: 'https://merchant.example/custom-ucp',
        }));
    });

    it('blocks toggling unsupported MCP transport', () => {
        const context = createContext({
            form: {
                enabledTransports: ['rest'],
            },
            transportOptions: [
                { value: 'rest', disabled: false },
                { value: 'mcp', disabled: true },
            ],
        });

        component.methods.updateTransport.call(context, 'mcp', true);

        expect(context.form.enabledTransports).toEqual(['rest']);
    });

    it('allows supported transport toggles', () => {
        const context = createContext({
            form: {
                enabledTransports: ['rest'],
            },
            transportOptions: [
                { value: 'rest', disabled: false },
                { value: 'a2a', disabled: false },
            ],
        });

        component.methods.updateTransport.call(context, 'a2a', true);
        component.methods.updateTransport.call(context, 'rest', false);

        expect(context.form.enabledTransports).toEqual(['a2a']);
    });

    it.each([
        ['strict signatures', { signaturePolicy: 'strict' }, 'showSignaturePolicyWarning', false],
        ['log signatures', { signaturePolicy: 'log' }, 'showSignaturePolicyWarning', true],
        ['empty split allowlists', {}, 'showAllowlistWarning', true],
        ['remote profile allowlist', { remoteProfileAllowlist: ['platform.example'] }, 'showAllowlistWarning', false],
        ['agent allowlist', { agentAllowlist: ['agent.example'] }, 'showAllowlistWarning', false],
        ['legacy allowlist', { platformAllowlist: ['legacy.example'] }, 'showAllowlistWarning', false],
    ])('computes warning state for %s', (_label, form, computedName, expected) => {
        const context = createContext({ form });

        expect(component.computed[computedName].call(context)).toBe(expected);
    });

    it('summarizes empty and populated transports', () => {
        expect(component.computed.transportSummaryLabel.call(createContext({
            form: { enabledTransports: [] },
        }))).toBe('sw-settings-ucp.general.noTransportLabel');

        expect(component.computed.transportSummaryLabel.call(createContext({
            form: { enabledTransports: ['rest', 'mcp'] },
        }))).toBe('REST, MCP');
    });

    it('detects Store API MCP support for transport options', () => {
        expect(component.methods.isTransportUnsupported.call(createContext({
            meta: { supportsStoreApiMcp: false },
        }), { requiresStoreApiMcp: true })).toBe(true);

        expect(component.methods.isTransportUnsupported.call(createContext({
            meta: { supportsStoreApiMcp: true },
        }), { requiresStoreApiMcp: true })).toBe(false);
    });

    // Cross-version value-binding guard. 6.5 (VUE3 flag off) emits `change` with the
    // value; 6.6+ emits `update:value`, and a native `change` can fall through as a DOM
    // Event. The guarded setters must apply real values (so 6.5 deactivation persists)
    // and ignore Event instances (so 6.6/6.7 are never clobbered).
    it('setValue applies a real value (6.5 emits `change` with the value)', () => {
        const context = createContext({ form: { active: true } });

        component.methods.setValue.call(context, 'active', false);

        expect(context.form.active).toBe(false);
    });

    it('setValue ignores a DOM Event (6.6/6.7 native `change` fallthrough)', () => {
        const context = createContext({ form: { active: true } });

        component.methods.setValue.call(context, 'active', new Event('change'));

        expect(context.form.active).toBe(true);
    });

    it('setKeyValue applies a real value and ignores a DOM Event', () => {
        const context = { newKeyKid: '' };

        component.methods.setKeyValue.call(context, 'newKeyKid', 'key-1');
        expect(context.newKeyKid).toBe('key-1');

        component.methods.setKeyValue.call(context, 'newKeyKid', new Event('change'));
        expect(context.newKeyKid).toBe('key-1');
    });

    it('setHostList applies a filtered array and never clears it on a DOM Event', () => {
        const context = createContext({ form: { agentAllowlist: [] } });

        component.methods.setHostList.call(context, 'agentAllowlist', ['a.example', '']);
        expect(context.form.agentAllowlist).toEqual(['a.example']);

        component.methods.setHostList.call(context, 'agentAllowlist', new Event('change'));
        expect(context.form.agentAllowlist).toEqual(['a.example']);
    });

    it('updateCapability toggles on a real value and ignores a DOM Event', () => {
        const context = createContext({ form: { enabledCapabilities: ['catalog'] } });

        component.methods.updateCapability.call(context, 'cart', true);
        expect(context.form.enabledCapabilities).toContain('cart');

        component.methods.updateCapability.call(context, 'order', new Event('change'));
        expect(context.form.enabledCapabilities).not.toContain('order');
    });

    it('updateTransport ignores a DOM Event (no transport added)', () => {
        const context = createContext({
            form: { enabledTransports: ['rest'] },
            transportOptions: [],
        });

        component.methods.updateTransport.call(context, 'sse', new Event('change'));

        expect(context.form.enabledTransports).toEqual(['rest']);
    });
});

function createContext(overrides = {}) {
    return {
        form: {
            ...defaultForm(),
            ...(overrides.form || {}),
        },
        meta: {
            supportsStoreApiMcp: true,
            ...(overrides.meta || {}),
        },
        transportOptions: overrides.transportOptions || [],
        $tc: (key) => key,
    };
}
