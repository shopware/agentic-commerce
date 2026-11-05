import { expect, test } from '@fixtures/AcceptanceTest';
import { fetchAgentCard, fetchDiscoveryDocument } from '@services/discovery';

test.describe('A2A binding @UcpKnownBlocked @UcpBlockedByD27', () => {
    test('the profile advertises the agent card as the A2A endpoint', async ({ TestDataService, browser }) => {
        const a2aChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(a2aChannel.salesChannel.id, { enabledTransports: ['rest', 'a2a'] });

        const discoveryDocument = await fetchDiscoveryDocument(browser, a2aChannel.url);

        const a2aEndpoints = Object.values(discoveryDocument.ucp.services).flat().filter(service => service.transport === 'a2a').map(service => service.endpoint);
        expect(a2aEndpoints).toEqual([`${a2aChannel.url.replace(/\/+$/, '')}/.well-known/agent-card.json`]);
    });

    test('the agent card advertises the UCP extension', async ({ TestDataService, browser }) => {
        const a2aChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(a2aChannel.salesChannel.id, { enabledTransports: ['rest', 'a2a'] });
        const { ucp: { version } } = await fetchDiscoveryDocument(browser, a2aChannel.url);

        const agentCard = await fetchAgentCard(browser, a2aChannel.url);

        const { extensions = [] } = agentCard as { extensions?: { uri: string }[] };
        expect(extensions.map(extension => extension.uri), JSON.stringify(agentCard, null, 2)).toContain(`https://ucp.dev/${version}/specification/reference`);
    });

    test('a message to add a product answers with the checkout in an a2a.ucp.checkout data part', async ({ TestDataService, UcpAgent }) => {
        const a2aChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(a2aChannel.salesChannel.id, { enabledTransports: ['rest', 'a2a'] });
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: a2aChannel.salesChannel.id, visibility: 30 }] });

        const messageSendResponse = await UcpAgent.at(a2aChannel.url).a2a.call('message/send', {
            message: {
                role: 'user',
                kind: 'message',
                messageId: crypto.randomUUID(),
                contextId: crypto.randomUUID(),
                parts: [{ type: 'data', data: { action: 'add_to_checkout', product_id: product.id, quantity: 1 } }],
            },
        });

        expect(messageSendResponse.status(), await messageSendResponse.text()).toBe(200);
        const { result } = (await messageSendResponse.json()) as { result?: { kind: string, parts: { data?: Record<string, { line_items: { item: { id: string } }[] }> }[] } };
        expect(result?.kind, await messageSendResponse.text()).toBe('message');
        const checkouts = (result?.parts ?? []).flatMap(part => part.data?.['a2a.ucp.checkout'] ?? []);
        expect(checkouts.flatMap(checkout => checkout.line_items.map(lineItem => lineItem.item.id))).toEqual([product.id]);
    });
});
