import { expect, test } from '@fixtures/AcceptanceTest';
import { CARTS_PATH, CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';

test.describe('Idempotency key scope @UcpKnownBlocked @UcpBlockedByD18', () => {
    test('an agent may reuse an idempotency key for another operation', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const lineItems = { line_items: [{ item: { id: product.id }, quantity: 1 }] };
        const sharedKey = crypto.randomUUID();
        const agent = UcpAgent.at(ucpChannel.url);

        const createdCart = await agent.rest.send('POST', CARTS_PATH, { body: lineItems, idempotencyKey: sharedKey });
        expect(createdCart.ok(), await createdCart.text()).toBeTruthy();

        const createdCheckout = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: lineItems, idempotencyKey: sharedKey });
        expect(createdCheckout.ok(), await createdCheckout.text()).toBeTruthy();
    });

    test('a second agent using the same idempotency key gets its own response', async ({ TestDataService, UcpAgent, UcpAgentB }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const cartRequest = { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] }, idempotencyKey: crypto.randomUUID() };

        const firstAgentCart = await UcpAgent.at(ucpChannel.url).rest.send('POST', CARTS_PATH, cartRequest);
        expect(firstAgentCart.ok(), await firstAgentCart.text()).toBeTruthy();
        const secondAgentCart = await UcpAgentB.at(ucpChannel.url).rest.send('POST', CARTS_PATH, cartRequest);
        expect(secondAgentCart.ok(), await secondAgentCart.text()).toBeTruthy();

        expect(secondAgentCart.headers()['idempotency-replay']).toBeUndefined();
        expect(((await secondAgentCart.json()) as { id: string }).id).not.toBe(((await firstAgentCart.json()) as { id: string }).id);
    });
});
