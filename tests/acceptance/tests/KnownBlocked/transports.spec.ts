import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('REST transport switched off @UcpKnownBlocked @UcpBlockedByD32', () => {
    test('a channel without REST does not serve the REST shopping routes', async ({ TestDataService, UcpAgent }) => {
        const a2aOnlyChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(a2aOnlyChannel.salesChannel.id, { enabledTransports: ['a2a'] });

        const cartCreation = await UcpAgent.at(a2aOnlyChannel.url).rest.send('POST', '/ucp/v1/carts', { body: { line_items: [] } });

        expect(cartCreation.status(), await cartCreation.text()).toBe(404);
    });
});
