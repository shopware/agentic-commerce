import { expect } from '@playwright/test';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { CHECKOUT_SESSIONS_PATH } from './shoppingRoutes';

export async function createCompletableCheckout(
    TestDataService: FixtureTypes['TestDataService'],
    UcpAgent: FixtureTypes['UcpAgent'],
) {
    const ucpChannel = await TestDataService.createStorefrontSalesChannel();
    await TestDataService.activateUcp(ucpChannel.salesChannel.id);
    const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
    const agent = UcpAgent.at(ucpChannel.url);
    const buyer = { email: `ucp-checkout-${crypto.randomUUID()}@example.com`, first_name: 'Ada', last_name: 'Lovelace' };
    const fulfillment = { methods: [{ type: 'shipping', destinations: [{ street_address: '1 Test Street', address_locality: 'London', postal_code: 'SW1A 1AA', address_country: 'GB' }] }] };
    const lineItems = [{ item: { id: product.id }, quantity: 1 }];

    const readyCheckout = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: lineItems, buyer, fulfillment } });
    expect(readyCheckout.ok(), await readyCheckout.text()).toBeTruthy();
    const { id: checkoutId } = (await readyCheckout.json()) as { id: string };

    return { agent, buyer, lineItems, channelUrl: ucpChannel.url, checkoutPath: `${CHECKOUT_SESSIONS_PATH}/${checkoutId}` };
}

export async function placeOrder(
    agent: Awaited<ReturnType<typeof createCompletableCheckout>>['agent'],
    checkoutPath: string,
): Promise<{ id: string }> {
    const completedCheckout = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: {} } });
    expect(completedCheckout.status(), await completedCheckout.text()).toBe(200);

    return ((await completedCheckout.json()) as { order: { id: string } }).order;
}
