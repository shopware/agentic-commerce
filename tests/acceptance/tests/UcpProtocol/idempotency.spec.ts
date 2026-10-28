import { expect, test } from '@fixtures/AcceptanceTest';
import { CARTS_PATH } from '@services/shoppingRoutes';
import { messageCodes } from '@services/ucpMessages';

test.describe('Idempotency @UcpProtocol', () => {
    test('a repeated request with the same key and body is answered from the cache', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const cartRequest = { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] }, idempotencyKey: crypto.randomUUID() };
        const agent = UcpAgent.at(ucpChannel.url);

        const originalCart = await agent.rest.send('POST', CARTS_PATH, cartRequest);
        expect(originalCart.ok(), await originalCart.text()).toBeTruthy();
        const retriedCart = await agent.rest.send('POST', CARTS_PATH, cartRequest);

        expect(retriedCart.status()).toBe(originalCart.status());
        expect(retriedCart.headers()['idempotency-replay']).toBe('1');
        expect(await retriedCart.text()).toBe(await originalCart.text());
    });

    test('a key reused with a different body is refused with 409', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const sharedKey = crypto.randomUUID();
        const agent = UcpAgent.at(ucpChannel.url);

        const originalCart = await agent.rest.send('POST', CARTS_PATH, { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] }, idempotencyKey: sharedKey });
        expect(originalCart.ok(), await originalCart.text()).toBeTruthy();
        const changedCart = await agent.rest.send('POST', CARTS_PATH, { body: { line_items: [{ item: { id: product.id }, quantity: 2 }] }, idempotencyKey: sharedKey });

        expect(changedCart.status(), await changedCart.text()).toBe(409);
        expect(await messageCodes(changedCart)).toContain('idempotency_conflict');
    });

    test('a channel that requires idempotency refuses a write without a key', async ({ TestDataService, UcpAgent }) => {
        const keyRequiringChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(keyRequiringChannel.salesChannel.id, { idempotencyRequired: true });
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: keyRequiringChannel.salesChannel.id, visibility: 30 }] });
        const cartBody = { line_items: [{ item: { id: product.id }, quantity: 1 }] };
        const agent = UcpAgent.at(keyRequiringChannel.url);

        const cartWithoutKey = await agent.rest.send('POST', CARTS_PATH, { body: cartBody, idempotencyKey: false });
        expect(cartWithoutKey.status(), await cartWithoutKey.text()).toBe(422);

        const cartWithKey = await agent.rest.send('POST', CARTS_PATH, { body: cartBody });
        expect(cartWithKey.ok(), await cartWithKey.text()).toBeTruthy();
    });
});
