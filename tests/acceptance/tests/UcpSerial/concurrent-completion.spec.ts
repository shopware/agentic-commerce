import { expect, test } from '@fixtures/AcceptanceTest';
import { searchIds } from '@services/adminSearch';
import { createCompletableCheckout } from '@services/completableCheckout';
import { isRefusal } from '@services/ucpMessages';

const PARALLEL_COMPLETIONS = 4;

test.describe('Concurrent completion @UcpSerial', () => {
    test('parallel completions of one checkout place exactly one order', async ({ TestDataService, UcpAgent }) => {
        const { agent, buyer, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);

        const completions = await Promise.all(Array.from({ length: PARALLEL_COMPLETIONS }, () =>
            agent.rest.send('POST', `${checkoutPath}/complete`, { body: { payment: {} } }),
        ));
        const statuses = completions.map(completion => completion.status());
        expect(statuses.filter(status => status >= 500), statuses.join(', ')).toEqual([]);

        const refusals = await Promise.all(completions.map(isRefusal));
        const placements = await Promise.all(completions.filter((_, index) => !refusals[index])
            .map(async completion => (await completion.json()) as { status: string, order: { id: string } }));
        const placedOrderIds = placements.map(placement => placement.order.id);

        expect(placements.length, statuses.join(', ')).toBeGreaterThan(0);
        expect(placements.map(placement => placement.status)).toEqual(placements.map(() => 'completed'));
        expect(new Set(placedOrderIds).size, placedOrderIds.join(', ')).toBe(1);
        expect(await searchIds(TestDataService, 'order', 'orderCustomer.email', buyer.email)).toEqual([placedOrderIds[0]]);
    });
});
