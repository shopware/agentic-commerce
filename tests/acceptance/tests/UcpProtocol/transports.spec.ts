import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('Transports @UcpProtocol', () => {
    test('a channel with only REST enabled refuses A2A and the embedded transport', async ({ TestDataService, UcpAgent, request }) => {
        const restOnlyChannel = await TestDataService.createStorefrontSalesChannel();
        // Without embedded origins, the plugin answers 403 before the SDK checks the transport.
        await TestDataService.activateUcp(restOnlyChannel.salesChannel.id, { enabledTransports: ['rest'], embeddedAllowedOrigins: ['https://agent.example'] });

        const a2aRefusalOnRestOnlyChannel = await UcpAgent.at(restOnlyChannel.url).a2a.call('get_cart', { id: crypto.randomUUID() });
        expect(a2aRefusalOnRestOnlyChannel.status(), await a2aRefusalOnRestOnlyChannel.text()).toBe(404);

        const embeddedRefusal = await request.get(`${restOnlyChannel.url}ucp/embedded/checkout/${crypto.randomUUID()}`);
        expect(embeddedRefusal.status(), await embeddedRefusal.text()).toBe(404);
    });
});
