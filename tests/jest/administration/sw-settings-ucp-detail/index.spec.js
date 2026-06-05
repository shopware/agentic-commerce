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
