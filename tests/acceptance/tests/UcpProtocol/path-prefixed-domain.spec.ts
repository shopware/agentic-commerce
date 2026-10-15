import { expect, test } from '@fixtures/AcceptanceTest';
import type { UcpProfileDocument } from '@services/UcpTestDataService';

test.describe('Path-prefixed sales channel domains @UcpProtocol', () => {
    test('the worker channel publishes its own profile under its path prefix', async ({ TestDataService, DefaultSalesChannel, request }) => {
        await TestDataService.activateUcp(DefaultSalesChannel.salesChannel.id);

        const profileResponse = await request.get(TestDataService.wellKnownUrl(DefaultSalesChannel.url));
        expect(profileResponse.ok(), await profileResponse.text()).toBeTruthy();

        const profile = (await profileResponse.json()) as UcpProfileDocument;
        const endpoints = Object.values(profile.ucp.services).flat().map(service => service.endpoint);
        expect(endpoints.length, 'an activated channel advertises the shopping service').toBeGreaterThan(0);
        for (const endpoint of endpoints) {
            expect(endpoint, 'every advertised endpoint stays inside the worker channel').toContain(DefaultSalesChannel.url);
        }
    });

    test('storefront pages point their service-meta link at the channel\'s own profile', async ({ TestDataService, request }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const homePage = await request.get(activatedChannel.url);
        const serviceMetaLink = homePage.headers()['link']?.match(/<([^>]+)>;\s*rel="service-meta"/)?.[1];

        expect(serviceMetaLink).toBeDefined();
        expect(new URL(serviceMetaLink!, activatedChannel.url).href).toBe(TestDataService.wellKnownUrl(activatedChannel.url));
    });

    test('the channel\'s strict signature policy accepts the agent\'s signature and refuses a tampered one', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { signaturePolicy: 'strict' });
        const unknownCartPath = `/ucp/v1/carts/${crypto.randomUUID().replaceAll('-', '')}`;

        const untamperedSignatureAcceptance = await UcpAgent.at(strictChannel.url).rest.send('GET', unknownCartPath);
        expect(untamperedSignatureAcceptance.status(), await untamperedSignatureAcceptance.text()).toBe(404);

        const tamperedSignatureRefusal = await UcpAgent.at(strictChannel.url).tampered({ tamperSignatureBytes: true }).send('GET', unknownCartPath);
        expect(tamperedSignatureRefusal.status(), await tamperedSignatureRefusal.text()).toBe(401);
    });

    test('a fresh channel on the same host advertises nothing until it is activated', async ({ TestDataService, request }) => {
        const freshChannelOnSameHost = await TestDataService.createStorefrontSalesChannel();

        const beforeActivation = await request.get(TestDataService.wellKnownUrl(freshChannelOnSameHost.url));
        expect(beforeActivation.ok(), await beforeActivation.text()).toBeTruthy();
        expect(((await beforeActivation.json()) as UcpProfileDocument).ucp.services).toEqual({});

        await TestDataService.activateUcp(freshChannelOnSameHost.salesChannel.id);

        const afterActivation = await request.get(TestDataService.wellKnownUrl(freshChannelOnSameHost.url));
        expect(afterActivation.ok(), await afterActivation.text()).toBeTruthy();
        expect(Object.keys(((await afterActivation.json()) as UcpProfileDocument).ucp.services)).not.toEqual([]);
    });
});
