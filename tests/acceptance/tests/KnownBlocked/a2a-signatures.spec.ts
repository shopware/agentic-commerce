import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('A2A signatures @UcpKnownBlocked @UcpBlockedByD3', () => {
    test('the strict policy refuses an unsigned A2A request with 401', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { enabledTransports: ['rest', 'a2a'], signaturePolicy: 'strict' });

        const refusal = await UcpAgent.at(strictChannel.url).unsigned.send('POST', '/ucp/a2a', {
            body: {
                jsonrpc: '2.0',
                id: crypto.randomUUID(),
                method: 'message/send',
                params: { message: { role: 'user', kind: 'message', messageId: crypto.randomUUID(), parts: [{ type: 'text', text: 'show me the catalog' }] } },
            },
        });

        expect(refusal.status(), await refusal.text()).toBe(401);
    });
});
