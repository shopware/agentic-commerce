import { expect, test } from '@fixtures/AcceptanceTest';
import { CARTS_PATH } from '@services/shoppingRoutes';
import { messageCodes } from '@services/ucpMessages';

test.describe('Cart cancel @UcpKnownBlocked @UcpBlockedByD23', () => {
    test('a cancelled cart answers with its last state and is gone afterwards', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const agent = UcpAgent.at(ucpChannel.url);
        const lineItems = [{ item: { id: product.id }, quantity: 1 }];

        const createdCart = await agent.rest.send('POST', CARTS_PATH, { body: { line_items: lineItems } });
        expect(createdCart.ok(), await createdCart.text()).toBeTruthy();
        const { id: cartId } = (await createdCart.json()) as { id: string };

        const cancelledCart = await agent.rest.send('POST', `${CARTS_PATH}/${cartId}/cancel`);
        expect(cancelledCart.status(), await cancelledCart.text()).toBe(200);
        const { line_items: lineItemsBeforeDeletion } = (await cancelledCart.json()) as { line_items: { item: { id: string } }[] };
        expect.soft(lineItemsBeforeDeletion.map(lineItem => lineItem.item.id)).toEqual([product.id]);

        const cartAfterCancel = await agent.rest.send('GET', `${CARTS_PATH}/${cartId}`);
        expect.soft(await messageCodes(cartAfterCancel), await cartAfterCancel.text()).toContain('not_found');
        const updateAfterCancel = await agent.rest.send('PUT', `${CARTS_PATH}/${cartId}`, { body: { line_items: lineItems } });
        expect.soft(await messageCodes(updateAfterCancel), await updateAfterCancel.text()).toContain('not_found');
    });
});
