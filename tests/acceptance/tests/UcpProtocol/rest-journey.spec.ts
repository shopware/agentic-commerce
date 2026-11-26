import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { placeOrder } from '@services/completableCheckout';
import { CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';
import { grandTotal, walkToCheckoutReadyForCompletion } from '@services/restJourney';

test.describe('REST shopping journey @UcpProtocol', () => {
    test('an agent finds a product and prepares a checkout that is ready for completion', async ({ TestDataService, UcpAgent }) => {
        await walkToCheckoutReadyForCompletion(TestDataService, UcpAgent);
    });

    test('an agent finds a product, buys it and the order reaches the shop', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, checkoutTotal, salesChannelId, checkoutPath } = await walkToCheckoutReadyForCompletion(TestDataService, UcpAgent);

        const orderId = await test.step('the checkout completes into an order the agent reads', async () => {
            const placedOrder = await placeOrder(TestDataService, agent, salesChannelId, checkoutPath);
            expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([placedOrder.id]);

            const orderRead = await agent.rest.send('GET', `/ucp/v1/orders/${placedOrder.id}`);
            expect(orderRead.status(), await orderRead.text()).toBe(200);
            expect(((await orderRead.json()) as { id: string }).id, 'the agent reads the order it placed').toBe(placedOrder.id);

            return placedOrder.id;
        });

        await test.step('the shop has the order in the channel', async () => {
            const shopOrder = await TestDataService.AdminApiClient.get(`./order/${orderId}`);
            expect(shopOrder.ok(), await shopOrder.text()).toBeTruthy();
            const { data: { salesChannelId: orderSalesChannelId, amountTotal } } = (await shopOrder.json()) as { data: { salesChannelId: string, amountTotal: number } };
            expect(orderSalesChannelId).toBe(salesChannelId);
            expect(Math.round(amountTotal * 100), 'the order total in minor units').toBe(checkoutTotal);
        });
    });

    test('a checkout applies the latest discount code and drops it again', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const tenPercentOff = await TestDataService.createPromotionWithCode({}, ucpChannel.salesChannel.id);
        const twentyPercentOff = await TestDataService.createPromotionWithCode({ discounts: [{ scope: 'cart', type: 'percentage', value: 20, considerAdvancedRules: false }] }, ucpChannel.salesChannel.id);
        const lineItems = [{ item: { id: product.id }, quantity: 1 }];
        const agent = UcpAgent.at(ucpChannel.url);

        const checkoutSession = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: lineItems } });
        expect(checkoutSession.ok(), await checkoutSession.text()).toBeTruthy();
        const { id: checkoutId } = (await checkoutSession.json()) as { id: string };
        const withCodes = (codes: string[]) => agent.rest.send('PUT', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`, { body: { line_items: lineItems, discounts: { codes } } });

        expect(await grandTotal(await withCodes([tenPercentOff.code]))).toBe(900);
        expect(await grandTotal(await withCodes([twentyPercentOff.code])), 'the second code replaces the first').toBe(800);
        expect(await grandTotal(await withCodes([])), 'an empty list removes the code').toBe(1000);
    });
});
