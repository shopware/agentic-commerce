import { expect, test } from '@fixtures/AcceptanceTest';
import { createCompletableCheckout, placeOrder } from '@services/completableCheckout';
import { isRefusal } from '@services/ucpMessages';

test.describe('Agent isolation @UcpKnownBlocked @UcpBlockedByD2', () => {
    test('another agent cannot read an order this agent placed', async ({ TestDataService, UcpAgent, UcpAgentB }) => {
        const { agent, buyer, channelUrl, salesChannelId, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
        const placedOrder = await placeOrder(TestDataService, agent, salesChannelId, checkoutPath);

        const foreignOrderRefusal = await UcpAgentB.at(channelUrl).rest.send('GET', `/ucp/v1/orders/${placedOrder.id}`);

        expect(await isRefusal(foreignOrderRefusal), await foreignOrderRefusal.text()).toBe(true);
        expect(await foreignOrderRefusal.text()).not.toContain(buyer.email);
    });
});
