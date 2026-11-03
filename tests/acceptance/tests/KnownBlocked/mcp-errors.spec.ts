import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { STORE_API_MCP_MISSING } from '@services/mcpSession';

test.describe('MCP signature errors @UcpKnownBlocked @UcpBlockedByD35', () => {
    test('the strict policy refuses an unsigned MCP request with a JSON-RPC error', async ({ InstanceMeta, TestDataService, UcpAgent }) => {
        test.skip(satisfies(InstanceMeta.version, STORE_API_MCP_MISSING), 'Store API MCP arrived in 6.7.12.0');
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { enabledTransports: ['rest', 'mcp'], signaturePolicy: 'strict' });
        const requestId = crypto.randomUUID();

        const unsignedRefusal = await UcpAgent.at(strictChannel.url).unsigned.send('POST', '/ucp/mcp', {
            body: { jsonrpc: '2.0', id: requestId, method: 'initialize', params: { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'ucp-acceptance', version: '1.0.0' } } },
        });

        expect(unsignedRefusal.status(), await unsignedRefusal.text()).toBe(401);
        expect(await unsignedRefusal.json()).toMatchObject({ jsonrpc: '2.0', id: requestId, error: { code: -32000 } });
    });
});
