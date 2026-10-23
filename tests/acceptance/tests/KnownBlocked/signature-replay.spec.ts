import { expect, test } from '@fixtures/AcceptanceTest';

const CATALOG_SEARCH_PATH = '/ucp/v1/catalog/search';
const catalogSearchRequest = { body: { query: 'product' } };

test.describe('Repeated signed request @UcpKnownBlocked @UcpBlockedByD16', () => {
    test('the strict policy answers a repeated signed request with the cached response', async ({ TestDataService, UcpAgent }) => {
        const signatureVerifyingChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(signatureVerifyingChannel.salesChannel.id, { signaturePolicy: 'strict' });
        const agent = UcpAgent.at(signatureVerifyingChannel.url);
        const searchToRepeat = await agent.signs('POST', CATALOG_SEARCH_PATH, catalogSearchRequest);

        const originalDelivery = await agent.tampered({ replay: searchToRepeat }).send('POST', CATALOG_SEARCH_PATH, catalogSearchRequest);
        const repeatedDelivery = await agent.tampered({ replay: searchToRepeat }).send('POST', CATALOG_SEARCH_PATH, catalogSearchRequest);

        expect(originalDelivery.status(), await originalDelivery.text()).toBe(200);
        expect(repeatedDelivery.status(), await repeatedDelivery.text()).toBe(200);
        expect(await repeatedDelivery.text()).toBe(await originalDelivery.text());
    });
});
