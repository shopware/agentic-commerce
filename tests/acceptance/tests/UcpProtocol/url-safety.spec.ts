import { expect, test } from '@fixtures/AcceptanceTest';
import { messageCodes } from '@services/ucpMessages';

const CATALOG_SEARCH_PATH = '/ucp/v1/catalog/search';

const unsafeProfileUrlByCase = {
    'a host outside the allowlist': 'https://not-allowlisted.ucp-acceptance.test/agent.json',
    'the cloud metadata host': 'https://169.254.169.254/agent.json',
    'a link-local address': 'https://169.254.10.10/agent.json',
    'a port other than 443 or 8443': 'https://agent.ucp-acceptance.test:8080/agent.json',
};

// 424 and the profile fetch codes mean the shop tried to fetch the profile.
function isRefusedBeforeFetch(refusal: { status: number, codes: string[] }): boolean {
    const fetchAttempted = refusal.status === 424 || refusal.codes.some(code => code === 'profile_unreachable' || code.startsWith('agent_profile_'));

    return refusal.status >= 400 && refusal.status < 500 && !fetchAttempted;
}

test.describe('Agent profile URL safety @UcpProtocol', () => {
    test('a request whose agent profile URL is unsafe is refused before the profile is fetched', async ({ TestDataService, UcpAgent }) => {
        const allowlistingChannel = await TestDataService.createStorefrontSalesChannel();
        const allowlistedHosts = [new URL(UcpAgent.identity.profileUrl).hostname, 'agent.ucp-acceptance.test', '169.254.169.254', '169.254.10.10'];
        await TestDataService.activateUcp(allowlistingChannel.salesChannel.id, { remoteProfileAllowlist: allowlistedHosts, agentAllowlist: allowlistedHosts, platformAllowlist: allowlistedHosts });
        const agent = UcpAgent.at(allowlistingChannel.url);

        const refusalByCase: Record<string, { status: number, codes: string[] }> = {};
        for (const [unsafeCase, profileUrl] of Object.entries(unsafeProfileUrlByCase)) {
            const refusal = await agent.rest.send('POST', CATALOG_SEARCH_PATH, { body: { query: 'product' }, headers: { 'UCP-Agent': `profile="${profileUrl}"` } });
            refusalByCase[unsafeCase] = { status: refusal.status(), codes: await messageCodes(refusal) };
        }

        const casesNotRefusedBeforeFetch = Object.entries(refusalByCase).filter(([, refusal]) => !isRefusedBeforeFetch(refusal));
        expect(casesNotRefusedBeforeFetch, JSON.stringify(refusalByCase, null, 2)).toEqual([]);
    });
});
