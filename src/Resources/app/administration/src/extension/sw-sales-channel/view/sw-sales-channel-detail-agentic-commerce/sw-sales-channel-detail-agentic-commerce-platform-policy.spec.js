/**
 * @sw-package discovery
 */
const register = jest.fn();

global.Shopware = {
    ...global.Shopware,
    Component: {
        getComponentRegistry: () => new Map(),
        register,
        override: jest.fn(),
    },
    Context: { app: { config: { version: '' } } },
};

require('./index');

const { platformPolicyOptions, platformAllowlistHint, platformAllowlistLabel } = register.mock.calls[0][1].computed;

const vmWithPolicy = (platformPolicy) => ({ form: { platformPolicy }, $t: (key) => key, $tc: (key) => key });

describe('sw-sales-channel-detail-agentic-commerce platform policy', () => {
    it('offers all platforms first, then listed platforms only', () => {
        expect(platformPolicyOptions.call(vmWithPolicy('all'))).toEqual([
            { value: 'all', label: 'sw-sales-channel.detail.agenticCommerce.ucp.platformPolicyAll' },
            { value: 'listed', label: 'sw-sales-channel.detail.agenticCommerce.ucp.platformPolicyListed' },
        ]);
    });

    it('explains the allowlist as pre-approved platforms when all platforms are accepted', () => {
        expect(platformAllowlistHint.call(vmWithPolicy('all')))
            .toBe('sw-sales-channel.detail.agenticCommerce.ucp.platformAllowlistHintAll');
    });

    it('names the list pre-approved platforms when all platforms are accepted, and allowed platforms otherwise', () => {
        expect(platformAllowlistLabel.call(vmWithPolicy('all')))
            .toBe('sw-sales-channel.detail.agenticCommerce.ucp.platformAllowlistLabelAll');
        expect(platformAllowlistLabel.call(vmWithPolicy('listed')))
            .toBe('sw-sales-channel.detail.agenticCommerce.ucp.platformAllowlistLabel');
    });

    it('explains the allowlist as the only accepted platforms when only listed platforms are accepted', () => {
        expect(platformAllowlistHint.call(vmWithPolicy('listed')))
            .toBe('sw-sales-channel.detail.agenticCommerce.ucp.platformAllowlistHintListed');
    });
});
