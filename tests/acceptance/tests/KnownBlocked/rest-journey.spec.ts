import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { placeOrder } from '@services/completableCheckout';
import { walkToCheckoutReadyForCompletion } from '@services/restJourney';

test.describe('REST shopping journey @UcpKnownBlocked @UcpBlockedByD2', () => {
    test('an agent finds a product, buys it and the order reaches the shop', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, checkoutTotal, salesChannelId, checkoutPath } = await walkToCheckoutReadyForCompletion(TestDataService, UcpAgent);

        const orderId = await test.step('the checkout completes into an order the agent reads', async () => {
            const placedOrder = await placeOrder(TestDataService, agent, salesChannelId, checkoutPath);
            expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([placedOrder.id]);

            const orderRead = await agent.rest.send('GET', `/ucp/v1/orders/${placedOrder.id}`);
            expect(orderRead.status(), await orderRead.text()).toBe(200);
            expect(((await orderRead.json()) as { id: string }).id, 'the agent reads the order it placed').toBe(placedOrder.id);

            return placedOrder.id;
        });

        await test.step('the shop has the order in the channel', async () => {
            const shopOrder = await TestDataService.AdminApiClient.get(`./order/${orderId}`);
            expect(shopOrder.ok(), await shopOrder.text()).toBeTruthy();
            const { data: { salesChannelId: orderSalesChannelId, amountTotal } } = (await shopOrder.json()) as { data: { salesChannelId: string, amountTotal: number } };
            expect(orderSalesChannelId).toBe(salesChannelId);
            expect(Math.round(amountTotal * 100), 'the order total in minor units').toBe(checkoutTotal);
        });
    });
});
