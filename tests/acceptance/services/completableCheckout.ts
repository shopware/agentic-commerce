import { expect } from '@playwright/test';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { CHECKOUT_SESSIONS_PATH } from './shoppingRoutes';

const INVOICE_HANDLER_ID = 'com.shopware.invoice';

export const invoiceInstrument = { id: 'instrument-1', handler_id: INVOICE_HANDLER_ID, type: 'invoice' };

export async function createCompletableCheckout(
    TestDataService: FixtureTypes['TestDataService'],
    UcpAgent: FixtureTypes['UcpAgent'],
    salesChannelOverrides: Record<string, unknown> = {},
) {
    const ucpChannel = await TestDataService.createStorefrontSalesChannel(salesChannelOverrides);
    await TestDataService.activateUcp(ucpChannel.salesChannel.id);
    const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
    const agent = UcpAgent.at(ucpChannel.url);
    const buyer = { email: `ucp-checkout-${crypto.randomUUID()}@example.com`, first_name: 'Ada', last_name: 'Lovelace' };
    const fulfillment = { methods: [{ type: 'shipping', destinations: [{ street_address: '1 Test Street', address_locality: 'London', postal_code: 'SW1A 1AA', address_country: 'GB' }] }] };
    const lineItems = [{ item: { id: product.id }, quantity: 1 }];

    const readyCheckout = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: lineItems, buyer, fulfillment } });
    expect(readyCheckout.ok(), await readyCheckout.text()).toBeTruthy();
    const { id: checkoutId } = (await readyCheckout.json()) as { id: string };

    return { agent, buyer, fulfillment, lineItems, salesChannelId: ucpChannel.salesChannel.id, channelUrl: ucpChannel.url, checkoutPath: `${CHECKOUT_SESSIONS_PATH}/${checkoutId}` };
}

export async function completionWithAdvertisedHandler(
    TestDataService: FixtureTypes['TestDataService'],
    salesChannelId: string,
) {
    const { payment_handlers: advertisedHandlers = {} } = await TestDataService.getProfilePreview(salesChannelId);
    const advertisedHandlerIds = Object.values(advertisedHandlers).flat().map(advertisedHandler => advertisedHandler.id);
    expect(advertisedHandlerIds, 'the profile does not advertise the invoice handler').toContain(INVOICE_HANDLER_ID);

    return { payment: { instruments: [invoiceInstrument] } };
}

export async function placeOrder(
    TestDataService: FixtureTypes['TestDataService'],
    agent: Awaited<ReturnType<typeof createCompletableCheckout>>['agent'],
    salesChannelId: string,
    checkoutPath: string,
): Promise<{ id: string }> {
    const completedCheckout = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: await completionWithAdvertisedHandler(TestDataService, salesChannelId) });
    expect(completedCheckout.status(), await completedCheckout.text()).toBe(200);
    const { status, order } = (await completedCheckout.json()) as { status: string, order: { id: string } };
    expect(status).toBe('completed');

    return order;
}
