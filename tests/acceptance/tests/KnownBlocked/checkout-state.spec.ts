import { expect, test, type FixtureTypes } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';

const CHECKOUT_SESSIONS_PATH = '/ucp/v1/checkout-sessions';

async function completableCheckout(TestDataService: FixtureTypes['TestDataService'], UcpAgent: FixtureTypes['UcpAgent']) {
    const ucpChannel = await TestDataService.createStorefrontSalesChannel();
    await TestDataService.activateUcp(ucpChannel.salesChannel.id);
    const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
    const agent = UcpAgent.at(ucpChannel.url);
    const buyer = { email: `ucp-state-${crypto.randomUUID()}@example.com`, first_name: 'Ada', last_name: 'Lovelace' };
    const fulfillment = { methods: [{ type: 'shipping', destinations: [{ street_address: '1 Test Street', address_locality: 'London', postal_code: 'SW1A 1AA', address_country: 'GB' }] }] };
    const lineItems = [{ item: { id: product.id }, quantity: 1 }];

    const readyCheckout = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: lineItems, buyer, fulfillment } });
    expect(readyCheckout.ok(), await readyCheckout.text()).toBeTruthy();
    const { id: checkoutId } = (await readyCheckout.json()) as { id: string };

    return { agent, buyer, lineItems, checkoutPath: `${CHECKOUT_SESSIONS_PATH}/${checkoutId}` };
}

test.describe('Checkout state @UcpKnownBlocked @UcpBlockedByD22', () => {
    test('a completed checkout cannot be cancelled', async ({ TestDataService, UcpAgent }) => {
        const { agent, checkoutPath } = await completableCheckout(TestDataService, UcpAgent);
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
        const { agent, buyer, lineItems, checkoutPath } = await completableCheckout(TestDataService, UcpAgent);
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
