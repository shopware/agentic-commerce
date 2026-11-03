import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('REST transport switched off @UcpKnownBlocked @UcpBlockedByD32', () => {
    test('a channel without REST does not serve the REST shopping routes', async ({ TestDataService, UcpAgent }) => {
        const a2aOnlyChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(a2aOnlyChannel.salesChannel.id, { enabledTransports: ['a2a'] });

        const cartCreation = await UcpAgent.at(a2aOnlyChannel.url).rest.send('POST', '/ucp/v1/carts', { body: { line_items: [] } });

        expect(cartCreation.status(), await cartCreation.text()).toBe(404);
    });
});

test.describe('MCP transport switched off @UcpKnownBlocked @UcpBlockedByD34', () => {
    test('a channel without MCP does not serve the MCP endpoint', async ({ TestDataService, UcpAgent }) => {
        const channelWithoutMcp = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(channelWithoutMcp.salesChannel.id, { enabledTransports: ['rest'] });

        const mcpEndpointAnswer = await UcpAgent.at(channelWithoutMcp.url).mcp.call('initialize', { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'ucp-acceptance', version: '1.0.0' } });

        expect(mcpEndpointAnswer.status(), await mcpEndpointAnswer.text()).toBe(404);
    });
});

test.describe('Embedded transport switched off @UcpKnownBlocked @UcpBlockedByD33', () => {
    test('a channel without the embedded transport answers its preflight like its GET', async ({ TestDataService, request }) => {
        const restOnlyChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(restOnlyChannel.salesChannel.id, { enabledTransports: ['rest'], embeddedAllowedOrigins: ['https://agent.example'] });
        const embeddedCheckoutUrl = `${restOnlyChannel.url}ucp/embedded/checkout/${crypto.randomUUID()}`;

        const preflight = await request.fetch(embeddedCheckoutUrl, { method: 'OPTIONS', headers: { Origin: 'https://agent.example' } });

        expect(preflight.status(), JSON.stringify(preflight.headers())).toBe(404);
    });
});
