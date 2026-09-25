import { expect, test } from '@fixtures/AcceptanceTest';
import type { UcpProfileDocument } from '@services/UcpTestDataService';

/**
 * Known blocker D8. Core's RequestTransformer strips the sales channel's path prefix from the
 * request URI, and the plugin resolves the channel from that URI alone, so a prefixed domain
 * serves the host's root channel or the global configuration instead of its own.
 */
test.describe('Path-prefixed sales channel domains @UcpKnownBlocked @UcpBlockedByD8', () => {
    test('the worker channel publishes its own profile under its path prefix', async ({ TestDataService, DefaultSalesChannel, page }) => {
        await TestDataService.activateUcp(DefaultSalesChannel.salesChannel.id);

        const profileResponse = await page.request.get(TestDataService.wellKnownUrl(DefaultSalesChannel.url));
        expect(profileResponse.ok(), await profileResponse.text()).toBeTruthy();

        const profile = (await profileResponse.json()) as UcpProfileDocument;
        const endpoints = Object.values(profile.ucp.services).flat().map(service => service.endpoint);
        expect(endpoints.length, 'an activated channel advertises the shopping service').toBeGreaterThan(0);
        for (const endpoint of endpoints) {
            expect(endpoint, 'every advertised endpoint stays inside the worker channel').toContain(DefaultSalesChannel.url);
        }
    });

    test('a fresh channel on the same host advertises nothing until it is activated', async ({ TestDataService, page }) => {
        const freshChannelOnSameHost = await TestDataService.createStorefrontSalesChannel();

        const beforeActivation = await page.request.get(TestDataService.wellKnownUrl(freshChannelOnSameHost.url));
        expect(beforeActivation.ok(), await beforeActivation.text()).toBeTruthy();
        expect(((await beforeActivation.json()) as UcpProfileDocument).ucp.services).toEqual({});

        await TestDataService.activateUcp(freshChannelOnSameHost.salesChannel.id);

        const afterActivation = await page.request.get(TestDataService.wellKnownUrl(freshChannelOnSameHost.url));
        expect(afterActivation.ok(), await afterActivation.text()).toBeTruthy();
        expect(Object.keys(((await afterActivation.json()) as UcpProfileDocument).ucp.services)).not.toEqual([]);
    });
});
