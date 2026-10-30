import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { createCompletableCheckout } from '@services/completableCheckout';
import { isRefusal, messageCodes } from '@services/ucpMessages';

const unadvertisedCardInstrument = { id: 'instrument-1', handler_id: 'com.example.unadvertised', type: 'card', credential: { type: 'token', token: 'tok_test' } };

test.describe('Payment handlers in the UCP profile @UcpKnownBlocked @UcpBlockedByD2', () => {
    test('an activated channel advertises a payment handler an agent can complete a checkout with', async ({ TestDataService }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const activatedChannelProfilePreview = await TestDataService.getProfilePreview(activatedChannel.salesChannel.id);

        expect(Object.keys(activatedChannelProfilePreview.payment_handlers ?? {}), 'checkout.complete needs a payment instrument bound to an advertised handler').not.toHaveLength(0);
    });
});

test.describe('Payment with a handler the profile does not advertise @UcpKnownBlocked @UcpBlockedByD24', () => {
    test('completion is refused and places no order', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);

        const refusal = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: { instruments: [unadvertisedCardInstrument] } } });

        expect.soft(await isRefusal(refusal), await refusal.text()).toBe(true);
        expect.soft(await messageCodes(refusal)).toContain('payment_failed');
        expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([]);
    });
});
