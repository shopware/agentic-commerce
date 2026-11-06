import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('Signature without created @UcpKnownBlocked @UcpBlockedByD1', () => {
    test('the strict policy accepts a signature without the optional created parameter', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { signaturePolicy: 'strict' });

        const acceptanceWithoutCreated = await UcpAgent.at(strictChannel.url).tampered({ omitCreated: true }).send('POST', '/ucp/v1/catalog/search', { body: { query: 'product' } });

        expect(acceptanceWithoutCreated.status(), await acceptanceWithoutCreated.text()).toBe(200);
    });
});
