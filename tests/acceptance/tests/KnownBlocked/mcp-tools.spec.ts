import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { openMcpSession, STORE_API_MCP_MISSING, toolOutput } from '@services/mcpSession';
import { CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';

const UCP_SHOPPING_TOOLS = [
    'search_catalog', 'lookup_catalog', 'get_product',
    'create_cart', 'get_cart', 'update_cart', 'cancel_cart',
    'create_checkout', 'get_checkout', 'update_checkout', 'complete_checkout', 'cancel_checkout',
    'get_order',
];

test.describe('MCP tools @UcpKnownBlocked @UcpBlockedByD5', () => {
    test('tools/list offers every UCP shopping tool', async ({ InstanceMeta, TestDataService, UcpAgent }) => {
        test.skip(satisfies(InstanceMeta.version, STORE_API_MCP_MISSING), 'Store API MCP arrived in 6.7.12.0');
        const mcpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(mcpChannel.salesChannel.id, { enabledTransports: ['rest', 'mcp'] });
        const mcpConnection = await openMcpSession(UcpAgent.at(mcpChannel.url));

        const offeredToolsResponse = await mcpConnection.call('tools/list');

        expect(offeredToolsResponse.status(), await offeredToolsResponse.text()).toBe(200);
        const { result: { tools } } = (await offeredToolsResponse.json()) as { result: { tools: { name: string }[] } };
        expect(tools.map(tool => tool.name)).toEqual(expect.arrayContaining(UCP_SHOPPING_TOOLS));
    });

    test('create_checkout with meta and checkout arguments creates the checkout', async ({ InstanceMeta, TestDataService, UcpAgent }) => {
        test.skip(satisfies(InstanceMeta.version, STORE_API_MCP_MISSING), 'Store API MCP arrived in 6.7.12.0');
        const mcpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(mcpChannel.salesChannel.id, { enabledTransports: ['rest', 'mcp'] });
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: mcpChannel.salesChannel.id, visibility: 30 }] });
        const agent = UcpAgent.at(mcpChannel.url);
        const mcpConnection = await openMcpSession(agent);

        const checkoutCreation = await mcpConnection.call('tools/call', {
            name: 'create_checkout',
            arguments: {
                meta: { 'ucp-agent': { profile: agent.identity.profileUrl } },
                checkout: { line_items: [{ item: { id: product.id }, quantity: 1 }] },
            },
        }, { ucpAgentHeader: false, idempotencyKey: false });

        expect(checkoutCreation.status(), await checkoutCreation.text()).toBe(200);
        const { id: checkoutId } = (await toolOutput(checkoutCreation)) as { id?: string };
        expect(checkoutId, await checkoutCreation.text()).toEqual(expect.any(String));
        const storedCheckout = await agent.rest.send('GET', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`);
        expect(storedCheckout.status(), await storedCheckout.text()).toBe(200);
        const { line_items: storedLineItems } = (await storedCheckout.json()) as { line_items: { item: { id: string } }[] };
        expect(storedLineItems.map(lineItem => lineItem.item.id)).toEqual([product.id]);
    });

    test('cancel_checkout with the idempotency key in meta cancels the checkout', async ({ InstanceMeta, TestDataService, UcpAgent }) => {
        test.skip(satisfies(InstanceMeta.version, STORE_API_MCP_MISSING), 'Store API MCP arrived in 6.7.12.0');
        const mcpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(mcpChannel.salesChannel.id, { enabledTransports: ['rest', 'mcp'] });
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: mcpChannel.salesChannel.id, visibility: 30 }] });
        const agent = UcpAgent.at(mcpChannel.url);
        const checkoutSession = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] } });
        expect(checkoutSession.ok(), await checkoutSession.text()).toBeTruthy();
        const { id: checkoutId } = (await checkoutSession.json()) as { id: string };
        const mcpConnection = await openMcpSession(agent);

        const checkoutCancellation = await mcpConnection.call('tools/call', {
            name: 'cancel_checkout',
            arguments: {
                meta: { 'ucp-agent': { profile: agent.identity.profileUrl }, 'idempotency-key': crypto.randomUUID() },
                id: checkoutId,
            },
        }, { ucpAgentHeader: false, idempotencyKey: false });

        expect(checkoutCancellation.status(), await checkoutCancellation.text()).toBe(200);
        const { status } = (await toolOutput(checkoutCancellation)) as { status?: string };
        expect(status, await checkoutCancellation.text()).toBe('canceled');
    });
});
