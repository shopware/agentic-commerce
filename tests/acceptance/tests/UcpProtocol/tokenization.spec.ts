import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('Payment tokenization @UcpProtocol', () => {
    test('a channel without a tokenizing payment handler answers tokenize with 501', async ({ TestDataService, UcpAgent }) => {
        const channelWithoutTokenizingHandler = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(channelWithoutTokenizingHandler.salesChannel.id);

        const tokenizeRefusal = await UcpAgent.at(channelWithoutTokenizingHandler.url).rest.send('POST', '/ucp/v1/tokenize', {
            body: {
                type: 'tokenized',
                handler_id: 'test',
                credential: { type: 'test' },
                binding: { type: 'dev.ucp.shopping.checkout', id: crypto.randomUUID() },
            },
        });

        expect(tokenizeRefusal.status(), await tokenizeRefusal.text()).toBe(501);
    });
});
