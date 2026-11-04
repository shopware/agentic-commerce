/**
 * @sw-package discovery
 *
 * The platform allowlist is the only way a SaaS merchant can let an agent with a
 * profile on another host use the sales channel, see shopware/agentic-commerce#282.
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

const { setPlatformAllowlist, isValidPlatformHost } = register.mock.calls[0][1].methods;

describe('sw-sales-channel-detail-agentic-commerce platform allowlist', () => {
    it('stores a pasted profile URL as its bare host', () => {
        const vm = { form: { platformAllowlist: [] } };

        setPlatformAllowlist.call(vm, ['https://platform.example/.well-known/ucp']);

        expect(vm.form.platformAllowlist).toEqual(['platform.example']);
    });

    it('ignores the native change event the 6.5 component lets through', () => {
        const vm = { form: { platformAllowlist: ['platform.example'] } };

        setPlatformAllowlist.call(vm, new Event('change'));

        expect(vm.form.platformAllowlist).toEqual(['platform.example']);
    });

    it('refuses an entry that is not a host before it reaches the server', () => {
        expect(isValidPlatformHost('platform example')).toBe(false);
        expect(isValidPlatformHost('platform.example')).toBe(true);
    });
});
