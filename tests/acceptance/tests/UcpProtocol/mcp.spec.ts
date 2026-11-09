import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { fetchDiscoveryDocument } from '@services/discovery';
import { openMcpSession, STORE_API_MCP_MISSING } from '@services/mcpSession';
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- typescript-eslint does not see the {@link} reference below
import type { knownBlockers } from '../../known-blockers';

/** The binding's tools minus get_product, which the plugin does not offer yet (known blocker D5 in {@link knownBlockers}). */
const OFFERED_UCP_SHOPPING_TOOLS = [
    'search_catalog', 'lookup_catalog',
    'create_cart', 'get_cart', 'update_cart', 'cancel_cart',
    'create_checkout', 'get_checkout', 'update_checkout', 'complete_checkout', 'cancel_checkout',
    'get_order',
];

test.describe('MCP transport @UcpProtocol', () => {
    test.beforeEach(({ InstanceMeta }) => {
        test.skip(satisfies(InstanceMeta.version, STORE_API_MCP_MISSING), 'Store API MCP arrived in 6.7.12.0');
    });

    test('the profile advertises the MCP endpoint with the MCP service schema', async ({ TestDataService, browser }) => {
        const ownHostMcpChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(ownHostMcpChannel.salesChannel.id, { enabledTransports: ['rest', 'mcp'] });

        const { ucp } = await fetchDiscoveryDocument(browser, ownHostMcpChannel.url);

        const mcpServices = Object.values(ucp.services).flat().filter(service => service.transport === 'mcp');
        expect(mcpServices).toEqual([expect.objectContaining({
            endpoint: `${ownHostMcpChannel.url.replace(/\/+$/, '')}/ucp/mcp`,
            schema: `https://ucp.dev/${ucp.version}/services/shopping/mcp.openrpc.json`,
        })]);
    });

    test('an initialized session lists the UCP shopping tools', async ({ TestDataService, UcpAgent }) => {
        const mcpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(mcpChannel.salesChannel.id, { enabledTransports: ['rest', 'mcp'] });
        const mcpSession = await openMcpSession(UcpAgent.at(mcpChannel.url));

        const offeredToolsResponse = await mcpSession.call('tools/list');

        expect(offeredToolsResponse.status(), await offeredToolsResponse.text()).toBe(200);
        const { result: { tools } } = (await offeredToolsResponse.json()) as { result: { tools: { name: string }[] } };
        expect(tools.map(tool => tool.name)).toEqual(expect.arrayContaining(OFFERED_UCP_SHOPPING_TOOLS));
    });

    test('the strict policy accepts a signed MCP request and refuses an unsigned one with 401', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { enabledTransports: ['rest', 'mcp'], signaturePolicy: 'strict' });
        const agent = UcpAgent.at(strictChannel.url);
        const initializeRequest = { jsonrpc: '2.0', id: crypto.randomUUID(), method: 'initialize', params: { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'ucp-acceptance', version: '1.0.0' } } };

        const signedAcceptance = await agent.rest.send('POST', '/ucp/mcp', { body: initializeRequest });
        expect(signedAcceptance.status(), await signedAcceptance.text()).toBe(200);

        const unsignedRefusal = await agent.unsigned.send('POST', '/ucp/mcp', { body: initializeRequest });
        expect(unsignedRefusal.status(), await unsignedRefusal.text()).toBe(401);
    });
});

test.describe('MCP transport without Store API MCP @UcpProtocol', () => {
    test('the profile does not advertise MCP although it is enabled', async ({ InstanceMeta, TestDataService, browser }) => {
        test.skip(!satisfies(InstanceMeta.version, STORE_API_MCP_MISSING), 'Store API MCP exists from 6.7.12.0');
        const mcpEnabledChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(mcpEnabledChannel.salesChannel.id, { enabledTransports: ['rest', 'mcp'] });

        const { ucp } = await fetchDiscoveryDocument(browser, mcpEnabledChannel.url);

        const mcpServices = Object.values(ucp.services).flat().filter(service => service.transport === 'mcp');
        expect(mcpServices).toEqual([]);
    });
});
