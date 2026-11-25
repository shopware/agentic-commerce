import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { completionWithAdvertisedHandler, createCompletableCheckout, placeOrder } from '@services/completableCheckout';
import { isRefusal, messageCodes } from '@services/ucpMessages';

test.describe('Checkout state @UcpKnownBlocked @UcpBlockedByD22 @UcpBlockedByD2', () => {
    test('a completed checkout cannot be cancelled', async ({ TestDataService, UcpAgent }) => {
        const { agent, salesChannelId, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
        const placedOrder = await placeOrder(TestDataService, agent, salesChannelId, checkoutPath);

        const cancelAfterCompletion = await agent.rest.send('POST', `${checkoutPath}/cancel`);
        expect(await isRefusal(cancelAfterCompletion), await cancelAfterCompletion.text()).toBe(true);

        const storedCheckout = await agent.rest.send('GET', checkoutPath);
        expect(storedCheckout.status(), await storedCheckout.text()).toBe(200);
        expect(((await storedCheckout.json()) as { order: { id: string } }).order.id).toBe(placedOrder.id);
    });

    test('a cancelled checkout stays cancelled', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, lineItems, salesChannelId, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
        const completionRequest = await completionWithAdvertisedHandler(TestDataService, salesChannelId);
        const cancelledCheckout = await agent.rest.send('POST', `${checkoutPath}/cancel`);
        expect(cancelledCheckout.status(), await cancelledCheckout.text()).toBe(200);

        const storedCheckout = await agent.rest.send('GET', checkoutPath);
        expect(((await storedCheckout.json()) as { status: string }).status).toBe('canceled');
        const repeatedCancel = await agent.rest.send('POST', `${checkoutPath}/cancel`);
        expect(await isRefusal(repeatedCancel), await repeatedCancel.text()).toBe(true);
        const updateAfterCancel = await agent.rest.send('PUT', checkoutPath, { body: { line_items: lineItems, buyer } });
        expect(await isRefusal(updateAfterCancel), await updateAfterCancel.text()).toBe(true);
        const refusalAfterCancel = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: completionRequest });
        expect(await isRefusal(refusalAfterCancel), await refusalAfterCancel.text()).toBe(true);
        expect(await messageCodes(refusalAfterCancel), 'the cancelled state refuses it, not the payment').not.toContain('payment_failed');
        expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([]);
    });
});

test.describe('Checkout state @UcpKnownBlocked @UcpBlockedByD2', () => {
    test('a completed checkout keeps its one order', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, fulfillment, lineItems, salesChannelId, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
        const placedOrder = await placeOrder(TestDataService, agent, salesChannelId, checkoutPath);

        await test.step('completing again answers with the same order', async () => {
            const replayedCompletedCheckout = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: await completionWithAdvertisedHandler(TestDataService, salesChannelId) });
            expect(replayedCompletedCheckout.status(), await replayedCompletedCheckout.text()).toBe(200);
            const { status, order } = (await replayedCompletedCheckout.json()) as { status: string, order: { id: string } };
            expect(status).toBe('completed');
            expect(order.id).toBe(placedOrder.id);
            expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([placedOrder.id]);
        });

        await test.step('an update is refused', async () => {
            const updateAfterCompletion = await agent.rest.send('PUT', checkoutPath, { body: { line_items: lineItems.map(lineItem => ({ ...lineItem, quantity: 2 })), buyer, fulfillment } });
            expect(await isRefusal(updateAfterCompletion), await updateAfterCompletion.text()).toBe(true);

            const storedCheckout = await agent.rest.send('GET', checkoutPath);
            expect(storedCheckout.status(), await storedCheckout.text()).toBe(200);
            const { status, order } = (await storedCheckout.json()) as { status: string, order: { id: string } };
            expect(status).toBe('completed');
            expect(order.id).toBe(placedOrder.id);
        });
    });
});
