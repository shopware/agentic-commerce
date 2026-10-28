import { expect, test } from '@fixtures/AcceptanceTest';
import { CARTS_PATH, CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';

const unknownCodes = { codes: ['NO-SUCH-CODE'] };

test.describe('Rejected discount code @UcpKnownBlocked @UcpBlockedByD19', () => {
    test('an unknown code on a checkout is reported as the warning discount_code_invalid', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const lineItems = [{ item: { id: product.id }, quantity: 1 }];
        const agent = UcpAgent.at(ucpChannel.url);

        const checkoutBeforeDiscount = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: lineItems } });
        expect(checkoutBeforeDiscount.ok(), await checkoutBeforeDiscount.text()).toBeTruthy();
        const { id: checkoutId } = (await checkoutBeforeDiscount.json()) as { id: string };

        const unknownCodeRefusal = await agent.rest.send('PUT', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`, { body: { line_items: lineItems, discounts: unknownCodes } });
        const { messages = [] } = (await unknownCodeRefusal.json()) as { messages?: { type?: string, code?: string }[] };

        expect(messages.map(message => ({ type: message.type, code: message.code }))).toContainEqual({ type: 'warning', code: 'discount_code_invalid' });
    });
});

test.describe('Discount codes on a cart @UcpKnownBlocked @UcpBlockedByD20', () => {
    test('an unknown code on a cart update is reported as rejected', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const lineItems = [{ item: { id: product.id }, quantity: 1 }];
        const agent = UcpAgent.at(ucpChannel.url);

        const cartBeforeDiscount = await agent.rest.send('POST', CARTS_PATH, { body: { line_items: lineItems } });
        expect(cartBeforeDiscount.ok(), await cartBeforeDiscount.text()).toBeTruthy();
        const { id: cartId } = (await cartBeforeDiscount.json()) as { id: string };

        const unknownCodeRefusal = await agent.rest.send('PUT', `${CARTS_PATH}/${cartId}`, { body: { line_items: lineItems, discounts: unknownCodes } });
        const { messages = [] } = (await unknownCodeRefusal.json()) as { messages?: unknown[] };

        expect(unknownCodeRefusal.status(), await unknownCodeRefusal.text()).toBe(200);
        expect(messages).not.toHaveLength(0);
    });
});
