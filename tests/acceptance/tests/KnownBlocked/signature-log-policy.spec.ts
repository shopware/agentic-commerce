import { expect, test } from '@fixtures/AcceptanceTest';
import { ShopwareLog } from '@services/shopwareLog';

test.describe('Signature policy log @UcpKnownBlocked @UcpBlockedByD14', () => {
    test('a request whose signature fails is accepted and logged with its keyid', async ({ TestDataService, UcpAgent }) => {
        const logPolicyChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(logPolicyChannel.salesChannel.id, { signaturePolicy: 'log' });
        const logBeforeRequest = ShopwareLog.mark();

        const tamperedSignatureReply = await UcpAgent.at(logPolicyChannel.url).tampered({ tamperSignatureBytes: true }).send('POST', '/ucp/v1/catalog/search', { body: { query: 'product' } });

        expect(tamperedSignatureReply.status(), await tamperedSignatureReply.text()).toBe(200);
        const agentLogLines = logBeforeRequest.writtenSinceMark().split('\n').filter(logLine => logLine.includes(UcpAgent.identity.kid));
        expect(agentLogLines, 'the failed verification is logged with the agent\'s keyid').toContainEqual(expect.stringMatching(/signature/i));
    });
});
