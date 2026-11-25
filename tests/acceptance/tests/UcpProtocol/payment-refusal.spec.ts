import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import type { APIResponse } from '@playwright/test';
import { searchIds } from '@services/adminSearch';
import { createCompletableCheckout } from '@services/completableCheckout';
import { isRefusal, messageCodes } from '@services/ucpMessages';

const cardInstrument = { id: 'instrument-1', handler_id: 'com.example.card', type: 'card', credential: { type: 'token', token: 'tok_test' } };
const unadvertisedCardInstrument = { ...cardInstrument, handler_id: 'com.example.unadvertised' };

async function expectRefusedCompletion(refusal: APIResponse, TestDataService: FixtureTypes['TestDataService'], buyerEmail: string): Promise<void> {
    expect.soft(refusal.status(), await refusal.text()).toBe(200);
    expect.soft(((await refusal.json()) as { status: string }).status).toBe('incomplete');
    expect.soft(await isRefusal(refusal), await refusal.text()).toBe(true);
    expect.soft(await messageCodes(refusal)).toContain('payment_failed');
    expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyerEmail)).toEqual([]);
}

test.describe('Payment with a handler the profile does not advertise @UcpProtocol', () => {
    test('completion is refused and places no order', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);

        const refusal = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: { instruments: [unadvertisedCardInstrument] } } });

        await expectRefusedCompletion(refusal, TestDataService, buyer.email);
    });
});

test.describe('Payment instrument count @UcpProtocol', () => {
    const instrumentCountCases = [
        { case: 'no instrument', payment: { instruments: [] } },
        { case: 'an empty payment object', payment: {} },
        { case: 'two instruments', payment: { instruments: [cardInstrument, { ...cardInstrument, id: 'instrument-2' }] } },
    ];

    for (const instrumentCountCase of instrumentCountCases) {
        test(`completion with ${instrumentCountCase.case} is refused with payment_failed`, async ({ TestDataService, UcpAgent }) => {
            const { agent, buyer, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);

            const wrongCountRefusal = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: instrumentCountCase.payment } });

            await expectRefusedCompletion(wrongCountRefusal, TestDataService, buyer.email);
        });
    }
});
