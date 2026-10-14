/**
 * @sw-package discovery
 *
 * The affiliate and campaign codes are stored on `sales_channel.configuration`, where the
 * product-export feeds read them back. Each field listens to both `@input` and `@update:value`
 * across admin versions, so a native input event must never be stored as a code.
 */

jest.mock('./sw-agentic-commerce-tracking-config.html.twig', () => 'mock-template', { virtual: true });

describe('sw-agentic-commerce-tracking-config', () => {
    let component;

    beforeEach(() => {
        const register = jest.fn();
        global.Shopware = {
            Component: { getComponentRegistry: () => new Map(), register, override: jest.fn() },
        };

        require('./index');

        [[, component]] = register.mock.calls;
    });

    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    function createInstance(salesChannel) {
        const instance = { salesChannel, $emit: jest.fn() };
        Object.defineProperty(instance, 'trackingConfig', { get: () => component.computed.trackingConfig.call(instance) });

        return instance;
    }

    it('creates the configuration on the sales channel when it has none', () => {
        const salesChannel = { configuration: null };

        const config = createInstance(salesChannel).trackingConfig;

        expect(salesChannel.configuration).toEqual({});
        expect(config).toBe(salesChannel.configuration);
    });

    it('stores both codes in the sales channel configuration and keeps other keys', () => {
        const salesChannel = { configuration: { productExportId: 'export-id' } };
        const instance = createInstance(salesChannel);

        component.methods.onAffiliateCodeChange.call(instance, 'partner-42');
        component.methods.onCampaignCodeChange.call(instance, 'spring-sale');

        expect(salesChannel.configuration).toEqual({
            productExportId: 'export-id',
            affiliateCode: 'partner-42',
            campaignCode: 'spring-sale',
        });
    });

    it.each([
        ['onAffiliateCodeChange', 'affiliateCode'],
        ['onCampaignCodeChange', 'campaignCode'],
    ])('%s emits a copy of the stored configuration', (handler, key) => {
        const salesChannel = { configuration: {} };
        const instance = createInstance(salesChannel);

        component.methods[handler].call(instance, 'code-42');

        expect(instance.$emit).toHaveBeenCalledWith('change', { [key]: 'code-42' });
        const [[, emitted]] = instance.$emit.mock.calls;
        expect(emitted).not.toBe(salesChannel.configuration);
    });

    it.each([
        ['onAffiliateCodeChange', 'affiliateCode'],
        ['onCampaignCodeChange', 'campaignCode'],
    ])('%s stores a cleared field as an empty string', (handler, key) => {
        const salesChannel = { configuration: { [key]: 'code-42' } };

        component.methods[handler].call(createInstance(salesChannel), null);

        expect(salesChannel.configuration[key]).toBe('');
    });

    it('ignores native input events instead of storing them as a code', () => {
        const salesChannel = { configuration: { affiliateCode: 'partner-42' } };
        const instance = createInstance(salesChannel);

        component.methods.onAffiliateCodeChange.call(instance, new Event('input'));
        component.methods.onCampaignCodeChange.call(instance, new Event('input'));

        expect(salesChannel.configuration).toEqual({ affiliateCode: 'partner-42' });
        expect(instance.$emit).not.toHaveBeenCalled();
    });
});