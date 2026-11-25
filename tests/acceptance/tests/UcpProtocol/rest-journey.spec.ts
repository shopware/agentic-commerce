import { expect, test } from '@fixtures/AcceptanceTest';
import { CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';
import { grandTotal, walkToCheckoutReadyForCompletion } from '@services/restJourney';

test.describe('REST shopping journey @UcpProtocol', () => {
    test('an agent finds a product and prepares a checkout that is ready for completion', async ({ TestDataService, UcpAgent }) => {
        await walkToCheckoutReadyForCompletion(TestDataService, UcpAgent);
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
