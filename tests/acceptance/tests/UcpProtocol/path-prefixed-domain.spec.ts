import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('Path-prefixed sales channel domains @UcpProtocol', () => {
    test('an activated channel serves no UCP profile below its path prefix', async ({ TestDataService, DefaultSalesChannel, request }) => {
        await TestDataService.activateUcp(DefaultSalesChannel.salesChannel.id);

        const profileResponse = await request.get(TestDataService.wellKnownUrl(DefaultSalesChannel.url));

        expect(profileResponse.status(), await profileResponse.text()).toBe(404);
    });

    test('storefront pages below a path prefix carry no service-meta link', async ({ TestDataService, request }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const homePage = await request.get(activatedChannel.url);

        expect(homePage.ok(), await homePage.text()).toBeTruthy();
        expect(homePage.headers()['link'] ?? '').not.toContain('rel="service-meta"');
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
});
