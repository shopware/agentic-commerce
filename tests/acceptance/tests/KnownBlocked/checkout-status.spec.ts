import { expect, test } from '@fixtures/AcceptanceTest';
import { CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';

test.describe('Checkout status @UcpKnownBlocked @UcpBlockedByD21', () => {
    test('a checkout without a shipping address is incomplete and says so', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const buyer = { email: `ucp-status-${crypto.randomUUID()}@example.com`, first_name: 'Ada', last_name: 'Lovelace' };

        const checkoutWithoutAddress = await UcpAgent.at(ucpChannel.url).rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: [{ item: { id: product.id }, quantity: 1 }], buyer } });
        expect(checkoutWithoutAddress.ok(), await checkoutWithoutAddress.text()).toBeTruthy();
        const { status, messages = [] } = (await checkoutWithoutAddress.json()) as { status: string, messages?: unknown[] };

        expect(status).toBe('incomplete');
        expect(messages).not.toHaveLength(0);
    });
});
