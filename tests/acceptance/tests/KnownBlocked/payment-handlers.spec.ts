import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { completionWithAdvertisedHandler, createCompletableCheckout } from '@services/completableCheckout';
import { isRefusal, messageCodes } from '@services/ucpMessages';

test.describe('Payment handlers in the UCP profile @UcpKnownBlocked @UcpBlockedByD2', () => {
    test('an activated channel advertises a payment handler an agent can complete a checkout with', async ({ TestDataService }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const activatedChannelProfilePreview = await TestDataService.getProfilePreview(activatedChannel.salesChannel.id);

        expect(Object.keys(activatedChannelProfilePreview.payment_handlers ?? {}), 'checkout.complete needs a payment instrument bound to an advertised handler').not.toHaveLength(0);
    });

    test('completion with two instruments of an advertised handler is refused with payment_failed', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, salesChannelId, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
        const { payment: { instruments: [advertisedInstrument] } } = await completionWithAdvertisedHandler(TestDataService, salesChannelId);

        const twoInstrumentRefusal = await agent.rest.send('POST', `${checkoutPath}/complete`, {
            body: { payment: { instruments: [advertisedInstrument, { ...advertisedInstrument, id: 'instrument-2' }] } },
        });

        expect.soft(twoInstrumentRefusal.status(), await twoInstrumentRefusal.text()).toBe(200);
        expect.soft(await isRefusal(twoInstrumentRefusal), await twoInstrumentRefusal.text()).toBe(true);
        expect.soft(await messageCodes(twoInstrumentRefusal)).toContain('payment_failed');
        expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([]);
    });
});
