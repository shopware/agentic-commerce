import { expect, test } from '@fixtures/AcceptanceTest';
import type { APIResponse } from '@playwright/test';
import { readUcpProtocolVersion } from '@services/pluginSource';

const CATALOG_SEARCH_PATH = '/ucp/v1/catalog/search';
const catalogSearchRequest = { body: { query: 'product' } };

async function messageCodes(response: APIResponse): Promise<string[]> {
    const { messages = [] } = (await response.json()) as { messages?: { code?: string }[] };

    return messages.map(message => message.code ?? '');
}

test.describe('UCP 2026-08-25 error codes @UcpKnownBlocked @UcpBlockedByD15', () => {
    test('the strict policy refuses an unsigned request as signature_missing', async ({ TestDataService, UcpAgent }) => {
        const channelRefusingUnsignedRequests = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(channelRefusingUnsignedRequests.salesChannel.id, { signaturePolicy: 'strict' });

        const refusal = await UcpAgent.at(channelRefusingUnsignedRequests.url).unsigned.send('POST', CATALOG_SEARCH_PATH, catalogSearchRequest);

        expect(refusal.status(), await refusal.text()).toBe(401);
        expect(await messageCodes(refusal)).toContain('signature_missing');
    });

    test('the strict policy refuses a body other than the signed one as digest_mismatch', async ({ TestDataService, UcpAgent }) => {
        const channelRefusingTamperedRequests = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(channelRefusingTamperedRequests.salesChannel.id, { signaturePolicy: 'strict' });

        const refusal = await UcpAgent.at(channelRefusingTamperedRequests.url).tampered({ tamperBody: Buffer.from(JSON.stringify({ query: 'tampered' }), 'utf8') }).send('POST', CATALOG_SEARCH_PATH, catalogSearchRequest);

        expect(refusal.status(), await refusal.text()).toBe(400);
        expect(await messageCodes(refusal)).toContain('digest_mismatch');
    });

    test('an agent profile that cannot be fetched is answered as profile_unreachable', async ({ TestDataService, UcpAgent }) => {
        const profileFetchingChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(profileFetchingChannel.salesChannel.id, { signaturePolicy: 'log' });
        const missingProfileUrl = UcpAgent.identity.profileUrl.replace(/[^/]+\.json$/, `missing-${crypto.randomUUID()}.json`);

        const refusal = await UcpAgent.at(profileFetchingChannel.url).unsigned.send('POST', CATALOG_SEARCH_PATH, { ...catalogSearchRequest, headers: { 'UCP-Agent': `profile="${missingProfileUrl}"` } });

        expect(refusal.status(), await refusal.text()).toBe(424);
        expect(await messageCodes(refusal)).toContain('profile_unreachable');
    });

    test('an agent profile whose service lacks the required transport is refused as profile_malformed', async ({ TestDataService, UcpAgent, UcpAgentProfileHost }) => {
        const profileFetchingChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(profileFetchingChannel.salesChannel.id, { signaturePolicy: 'log' });
        const ucpProtocolVersion = readUcpProtocolVersion();
        const agentWithoutTransport = await UcpAgentProfileHost.publish({
            services: {
                'dev.ucp.shopping': [{
                    version: ucpProtocolVersion,
                    spec: `https://ucp.dev/${ucpProtocolVersion}/specification/overview/`,
                    schema: `https://ucp.dev/${ucpProtocolVersion}/services/shopping/rest.openapi.json`,
                }],
            },
        });

        const refusal = await UcpAgent.at(profileFetchingChannel.url).unsigned.send('POST', CATALOG_SEARCH_PATH, { ...catalogSearchRequest, headers: { 'UCP-Agent': agentWithoutTransport.agentHeader } });

        expect(refusal.status(), await refusal.text()).toBe(422);
        expect(await messageCodes(refusal)).toContain('profile_malformed');
    });
});
