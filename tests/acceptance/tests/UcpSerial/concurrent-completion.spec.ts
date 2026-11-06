import { expect, test } from '@fixtures/AcceptanceTest';
import type { APIResponse } from '@playwright/test';
import { searchIds } from '@services/adminSearch';
import { COMPLETION_WITHOUT_INSTRUMENT, createCompletableCheckout } from '@services/completableCheckout';

const PARALLEL_COMPLETIONS = 4;

// The shop refuses a completion while another completion of the same checkout holds its lock.
async function isRefusedWhileInFlight(completion: APIResponse): Promise<boolean> {
    if (completion.status() !== 422) {
        return false;
    }
    const { messages = [] } = (await completion.json()) as { messages?: { code?: string, content?: string }[] };

    return messages.some(message => message.code === 'invalid_request' && (message.content ?? '').startsWith('Checkout completion is already processing'));
}

test.describe('Concurrent completion @UcpSerial', () => {
    test('parallel completions of one checkout place exactly one order', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);

        const completions = await Promise.all(Array.from({ length: PARALLEL_COMPLETIONS }, () =>
            agent.rest.send('POST', `${checkoutPath}/complete`, { body: COMPLETION_WITHOUT_INSTRUMENT }),
        ));
        const answers = (await Promise.all(completions.map(async completion => `${completion.status()} ${await completion.text()}`))).join('\n');

        const inFlightRefusals = await Promise.all(completions.map(isRefusedWhileInFlight));
        const placements = completions.filter((_, index) => !inFlightRefusals[index]);
        expect(placements.length, answers).toBeGreaterThan(0);
        expect(placements.map(placement => placement.status()), answers).toEqual(placements.map(() => 200));

        const completedCheckouts = await Promise.all(placements.map(async placement => (await placement.json()) as { status: string, order?: { id: string } }));
        expect(completedCheckouts.map(completedCheckout => completedCheckout.status), answers).toEqual(completedCheckouts.map(() => 'completed'));
        const placedOrderIds = completedCheckouts.map(completedCheckout => completedCheckout.order?.id);
        expect(new Set(placedOrderIds).size, answers).toBe(1);
        expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([placedOrderIds[0]]);
    });
});
