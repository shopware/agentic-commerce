import { UCP_VERSION } from 'Resources/module/sw-settings-ucp/ucp-protocol.js';
import {
    defaultForm,
    normalizeConfig,
    toggleArrayValue,
} from 'Resources/module/sw-settings-ucp/page/sw-settings-ucp-detail/form-state.js';

describe('sw-settings-ucp-detail/form-state', () => {
    it('builds the default admin config form', () => {
        expect(defaultForm()).toEqual({
            active: false,
            ucpVersion: UCP_VERSION,
            profileUriStrategy: 'domain',
            customProfileUri: null,
            enabledCapabilities: ['catalog', 'cart', 'discount', 'checkout', 'order'],
            enabledTransports: ['rest'],
            continueUrlTemplate: null,
            platformAllowlist: [],
            remoteProfileAllowlist: [],
            agentAllowlist: [],
            embeddedAllowedOrigins: [],
            embeddedFrameAncestors: [],
            discoveryBudget: 10,
            webhookUrlOverride: null,
            signaturePolicy: 'strict',
            idempotencyRequired: true,
        });
    });

    it.each([
        [
            'preserves explicit empty arrays',
            {
                enabledCapabilities: [],
                enabledTransports: [],
                platformAllowlist: [],
                remoteProfileAllowlist: [],
                agentAllowlist: [],
                embeddedAllowedOrigins: [],
                embeddedFrameAncestors: [],
            },
            {
                enabledCapabilities: [],
                enabledTransports: [],
                platformAllowlist: [],
                remoteProfileAllowlist: [],
                agentAllowlist: [],
                embeddedAllowedOrigins: [],
                embeddedFrameAncestors: [],
            },
        ],
        [
            'falls back for non-array list values',
            {
                enabledCapabilities: null,
                enabledTransports: 'rest',
                platformAllowlist: false,
                remoteProfileAllowlist: 'platform.example',
                agentAllowlist: 1,
                embeddedAllowedOrigins: {},
                embeddedFrameAncestors: undefined,
            },
            {
                enabledCapabilities: ['catalog', 'cart', 'discount', 'checkout', 'order'],
                enabledTransports: ['rest'],
                platformAllowlist: [],
                remoteProfileAllowlist: [],
                agentAllowlist: [],
                embeddedAllowedOrigins: [],
                embeddedFrameAncestors: [],
            },
        ],
        [
            'keeps configured list values',
            {
                enabledCapabilities: ['catalog', 'payment_tokenization'],
                enabledTransports: ['rest', 'a2a'],
                platformAllowlist: ['legacy.example'],
                remoteProfileAllowlist: ['platform.example'],
                agentAllowlist: ['agent.example'],
                embeddedAllowedOrigins: ['https://assistant.example'],
                embeddedFrameAncestors: ['https://frame.example'],
            },
            {
                enabledCapabilities: ['catalog', 'payment_tokenization'],
                enabledTransports: ['rest', 'a2a'],
                platformAllowlist: ['legacy.example'],
                remoteProfileAllowlist: ['platform.example'],
                agentAllowlist: ['agent.example'],
                embeddedAllowedOrigins: ['https://assistant.example'],
                embeddedFrameAncestors: ['https://frame.example'],
            },
        ],
    ])('normalizes config arrays: %s', (_label, config, expected) => {
        expect(normalizeConfig(config)).toEqual(expect.objectContaining(expected));
    });

    it('normalizes empty optional strings to null', () => {
        expect(normalizeConfig({
            customProfileUri: '',
            continueUrlTemplate: '',
            webhookUrlOverride: '',
        })).toEqual(expect.objectContaining({
            customProfileUri: null,
            continueUrlTemplate: null,
            webhookUrlOverride: null,
        }));
    });

    it.each([
        ['adds a missing entry', ['rest'], 'a2a', true, ['rest', 'a2a']],
        ['keeps an existing enabled entry once', ['rest'], 'rest', true, ['rest']],
        ['removes an existing entry', ['rest', 'a2a'], 'a2a', false, ['rest']],
        ['keeps a missing disabled entry absent', ['rest'], 'mcp', false, ['rest']],
    ])('toggles array values: %s', (_label, values, entry, enabled, expected) => {
        const original = [...values];
        const result = toggleArrayValue(values, entry, enabled);

        expect(result).toEqual(expected);
        expect(values).toEqual(original);
        expect(result).not.toBe(values);
    });
});
