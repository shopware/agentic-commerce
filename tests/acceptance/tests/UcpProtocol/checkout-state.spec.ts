import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';
import { isRefusal } from '@services/ucpMessages';

test.describe('Checkout state @UcpProtocol', () => {
    test('a completed checkout keeps its one order', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const agent = UcpAgent.at(ucpChannel.url);
        const lineItems = [{ item: { id: product.id }, quantity: 1 }];
        const buyer = { email: `ucp-state-${crypto.randomUUID()}@example.com`, first_name: 'Ada', last_name: 'Lovelace' };
        const fulfillment = { methods: [{ type: 'shipping', destinations: [{ street_address: '1 Test Street', address_locality: 'London', postal_code: 'SW1A 1AA', address_country: 'GB' }] }] };

        const readyCheckout = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: lineItems, buyer, fulfillment } });
        expect(readyCheckout.ok(), await readyCheckout.text()).toBeTruthy();
        const { id: checkoutId } = (await readyCheckout.json()) as { id: string };

        const completedCheckout = await agent.rest.send('POST', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}/complete`, { body: { payment: {} } });
        expect(completedCheckout.status(), await completedCheckout.text()).toBe(200);
        const { order: placedOrder } = (await completedCheckout.json()) as { order: { id: string } };

        await test.step('completing again answers with the same order', async () => {
            const repeatedCompletion = await agent.rest.send('POST', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}/complete`, { body: { payment: {} } });
            expect(repeatedCompletion.status(), await repeatedCompletion.text()).toBe(200);
            const { status, order } = (await repeatedCompletion.json()) as { status: string, order: { id: string } };
            expect(status).toBe('completed');
            expect(order.id).toBe(placedOrder.id);
            expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([placedOrder.id]);
        });

        await test.step('an update is refused', async () => {
            const updateAfterCompletion = await agent.rest.send('PUT', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`, { body: { line_items: [{ item: { id: product.id }, quantity: 2 }], buyer, fulfillment } });
            expect(await isRefusal(updateAfterCompletion), await updateAfterCompletion.text()).toBe(true);

            const storedCheckout = await agent.rest.send('GET', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`);
            const { status, order } = (await storedCheckout.json()) as { status: string, order: { id: string } };
            expect(status).toBe('completed');
            expect(order.id).toBe(placedOrder.id);
        });
    });
});
