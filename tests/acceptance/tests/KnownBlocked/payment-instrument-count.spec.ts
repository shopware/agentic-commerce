import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { createCompletableCheckout } from '@services/completableCheckout';
import { isRefusal, messageCodes } from '@services/ucpMessages';

const cardInstrument = { id: 'instrument-1', handler_id: 'com.example.card', type: 'card', credential: { type: 'token', token: 'tok_test' } };

test.describe('Payment instrument count @UcpKnownBlocked @UcpBlockedByD25', () => {
    const instrumentCountCases = [
        { case: 'no instrument', instruments: [] },
        { case: 'two instruments', instruments: [cardInstrument, { ...cardInstrument, id: 'instrument-2' }] },
    ];

    for (const instrumentCountCase of instrumentCountCases) {
        test(`completion with ${instrumentCountCase.case} is refused with payment_failed`, async ({ TestDataService, UcpAgent }) => {
            const { agent, buyer, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);

            const wrongCountRefusal = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: { instruments: instrumentCountCase.instruments } } });

            expect.soft(await isRefusal(wrongCountRefusal), await wrongCountRefusal.text()).toBe(true);
            expect.soft(await messageCodes(wrongCountRefusal)).toContain('payment_failed');
            expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([]);
        });
    }
});
