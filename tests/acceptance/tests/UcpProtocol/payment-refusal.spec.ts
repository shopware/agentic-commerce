import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { createCompletableCheckout } from '@services/completableCheckout';
import { isRefusal, messageCodes } from '@services/ucpMessages';

const cardInstrument = { id: 'instrument-1', handler_id: 'com.example.card', type: 'card', credential: { type: 'token', token: 'tok_test' } };
const unadvertisedCardInstrument = { ...cardInstrument, handler_id: 'com.example.unadvertised' };

async function expectRefusedCompletion(
    checkout: Awaited<ReturnType<typeof createCompletableCheckout>>,
    TestDataService: FixtureTypes['TestDataService'],
    payment: Record<string, unknown>,
): Promise<void> {
    const { agent, buyer, checkoutPath } = checkout;
    const storedCheckout = await agent.rest.send('GET', checkoutPath);
    expect(storedCheckout.status(), await storedCheckout.text()).toBe(200);
    const { status: statusBeforeCompletion } = (await storedCheckout.json()) as { status: string };

    const refusal = await agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment } });

    expect.soft(refusal.status(), await refusal.text()).toBe(200);
    expect.soft(((await refusal.json()) as { status: string }).status, 'a refusal keeps the status of the checkout').toBe(statusBeforeCompletion);
    expect.soft(await isRefusal(refusal), await refusal.text()).toBe(true);
    expect.soft(await messageCodes(refusal)).toContain('payment_failed');
    expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([]);
}

test.describe('Payment with a handler the profile does not advertise @UcpProtocol', () => {
    test('completion is refused and places no order', async ({ TestDataService, UcpAgent }) => {
        const checkout = await createCompletableCheckout(TestDataService, UcpAgent);

        await expectRefusedCompletion(checkout, TestDataService, { instruments: [unadvertisedCardInstrument] });
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
            const checkout = await createCompletableCheckout(TestDataService, UcpAgent);

            await expectRefusedCompletion(checkout, TestDataService, instrumentCountCase.payment);
        });
    }
});
