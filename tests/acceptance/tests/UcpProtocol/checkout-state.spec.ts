import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { createCompletableCheckout, placeOrder } from '@services/completableCheckout';
import { isRefusal } from '@services/ucpMessages';

test.describe('Checkout state @UcpProtocol', () => {
    test('a completed checkout keeps its one order', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, fulfillment, lineItems, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
        const placedOrder = await placeOrder(agent, checkoutPath);

        await test.step('completing again answers with the same order', async () => {
            const repeatedCompletion = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: {} } });
            expect(repeatedCompletion.status(), await repeatedCompletion.text()).toBe(200);
            const { status, order } = (await repeatedCompletion.json()) as { status: string, order: { id: string } };
            expect(status).toBe('completed');
            expect(order.id).toBe(placedOrder.id);
            expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([placedOrder.id]);
        });

        await test.step('an update is refused', async () => {
            const updateAfterCompletion = await agent.rest.send('PUT', checkoutPath, { body: { line_items: lineItems.map(lineItem => ({ ...lineItem, quantity: 2 })), buyer, fulfillment } });
            expect(await isRefusal(updateAfterCompletion), await updateAfterCompletion.text()).toBe(true);

            const storedCheckout = await agent.rest.send('GET', checkoutPath);
            const { status, order } = (await storedCheckout.json()) as { status: string, order: { id: string } };
            expect(status).toBe('completed');
            expect(order.id).toBe(placedOrder.id);
        });
    });
});
