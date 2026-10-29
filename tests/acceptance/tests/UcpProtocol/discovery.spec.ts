import { expect, test } from '@fixtures/AcceptanceTest';
import { advertisedTransports, fetchDiscoveryDocument, fetchDiscoveryResponse, fetchStorefrontLinkHeader } from '@services/discovery';

test.describe('UCP discovery profile @UcpProtocol', () => {
    test('the profile is publicly cacheable for 60 seconds', async ({ TestDataService, browser }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const profileResponse = await fetchDiscoveryResponse(browser, activatedChannel.url);

        const cacheDirectives = (profileResponse.headers['cache-control'] ?? '').split(',').map(directive => directive.trim());
        expect(cacheDirectives).toContain('public');
        expect(cacheDirectives).toContain('max-age=60');
    });

    test('the profile names its UCP version and publishes EC P-256 signing keys', async ({ TestDataService, browser }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const profile = await fetchDiscoveryDocument(browser, activatedChannel.url);

        expect(profile.ucp.version).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(profile.keys.length, 'an activated channel publishes at least one signing key').toBeGreaterThan(0);
        for (const signingKey of profile.keys) {
            expect(signingKey).toMatchObject({ kty: 'EC', crv: 'P-256', alg: 'ES256' });
        }
    });

    test('the profile offers one service per enabled transport', async ({ TestDataService, browser }) => {
        const restOnlyChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(restOnlyChannel.salesChannel.id, { enabledTransports: ['rest'] });
        const restAndA2aChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(restAndA2aChannel.salesChannel.id, { enabledTransports: ['rest', 'a2a'] });

        const restOnlyProfile = await fetchDiscoveryDocument(browser, restOnlyChannel.url);
        const restAndA2aProfile = await fetchDiscoveryDocument(browser, restAndA2aChannel.url);

        expect(advertisedTransports(restOnlyProfile.ucp)).toEqual(['rest']);
        expect(advertisedTransports(restAndA2aProfile.ucp)).toEqual(['a2a', 'rest']);
    });

    test('every capability a capability extends is offered too', async ({ TestDataService, browser }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const profile = await fetchDiscoveryDocument(browser, activatedChannel.url);
        const offeredCapabilities = Object.keys(profile.ucp.capabilities);
        const extendedCapabilities = Object.values(profile.ucp.capabilities).flat().flatMap(capability => capability.extends ?? []);

        expect(extendedCapabilities.length, 'the default capability set contains an extension').toBeGreaterThan(0);
        for (const extendedCapability of extendedCapabilities) {
            expect(offeredCapabilities).toContain(extendedCapability);
        }
    });

    test('storefront pages link the profile as service-meta', async ({ TestDataService, browser }) => {
        const profileLinkingChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(profileLinkingChannel.salesChannel.id);

        const linkHeader = await fetchStorefrontLinkHeader(browser, profileLinkingChannel.url);

        expect(linkHeader).toContain('</.well-known/ucp>; rel="service-meta"');
    });
});
