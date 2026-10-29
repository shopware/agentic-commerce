import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { createCompletableCheckout } from '@services/completableCheckout';

test.describe('Checkout state @UcpKnownBlocked @UcpBlockedByD22', () => {
    test('a completed checkout cannot be cancelled', async ({ TestDataService, UcpAgent }) => {
        const { agent, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
        const completedCheckout = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: {} } });
        expect(completedCheckout.status(), await completedCheckout.text()).toBe(200);
        const { order: placedOrder } = (await completedCheckout.json()) as { order: { id: string } };

        const cancelAfterCompletion = await agent.rest.send('POST', `${checkoutPath}/cancel`);
        expect(cancelAfterCompletion.ok(), await cancelAfterCompletion.text()).toBeFalsy();

        const storedCheckout = await agent.rest.send('GET', checkoutPath);
        expect(storedCheckout.status(), await storedCheckout.text()).toBe(200);
        expect(((await storedCheckout.json()) as { order: { id: string } }).order.id).toBe(placedOrder.id);
    });

    test('a cancelled checkout stays cancelled', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, lineItems, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
        const cancelledCheckout = await agent.rest.send('POST', `${checkoutPath}/cancel`);
        expect(cancelledCheckout.status(), await cancelledCheckout.text()).toBe(200);

        const storedCheckout = await agent.rest.send('GET', checkoutPath);
        expect(((await storedCheckout.json()) as { status: string }).status).toBe('canceled');
        const repeatedCancel = await agent.rest.send('POST', `${checkoutPath}/cancel`);
        expect(repeatedCancel.ok(), await repeatedCancel.text()).toBeFalsy();
        const updateAfterCancel = await agent.rest.send('PUT', checkoutPath, { body: { line_items: lineItems, buyer } });
        expect(updateAfterCancel.ok(), await updateAfterCancel.text()).toBeFalsy();
        const completeAfterCancel = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: {} } });
        expect(completeAfterCancel.ok(), await completeAfterCancel.text()).toBeFalsy();
        expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([]);
    });
});
