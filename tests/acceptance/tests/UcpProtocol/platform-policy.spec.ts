import { expect, test } from '@fixtures/AcceptanceTest';

const CATALOG_SEARCH_PATH = '/ucp/v1/catalog/search';
const catalogSearch = { body: { query: 'product' } };

// The lists name another platform only, so the test agent's own profile host is not on any of them.
const anotherPlatformOnly = {
    signaturePolicy: 'strict' as const,
    platformAllowlist: ['another-platform.ucp-acceptance.test'],
    remoteProfileAllowlist: [],
    agentAllowlist: [],
};

test.describe('Platform policy @UcpProtocol', () => {
    test('a signed request from a platform outside the allowlist is refused under listed and served under all', async ({ TestDataService, UcpAgentWithProfile }) => {
        const channel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(channel.salesChannel.id, { ...anotherPlatformOnly, platformPolicy: 'listed' });
        // A profile published for this test, so the shop has no cached copy and has to fetch it.
        const unlistedAgent = (await UcpAgentWithProfile({})).at(channel.url);

        await test.step('listed platforms only refuses the platform before fetching its profile', async () => {
            const refusal = await unlistedAgent.rest.send('POST', CATALOG_SEARCH_PATH, catalogSearch);

            expect(refusal.status(), await refusal.text()).toBe(401);
            expect(await refusal.text()).toContain('Platform profile host is not allowed by the current runtime configuration.');
        });

        await test.step('all platforms fetches the profile and verifies the signature against its keys', async () => {
            const saved = await TestDataService.saveUcpConfig(channel.salesChannel.id, { platformPolicy: 'all' });
            expect(saved.ok(), await saved.text()).toBeTruthy();

            const served = await unlistedAgent.rest.send('POST', CATALOG_SEARCH_PATH, catalogSearch);

            expect(served.status(), await served.text()).toBe(200);
        });

        await test.step('all platforms still refuses a request whose signature does not verify', async () => {
            const refusal = await unlistedAgent.tampered({ tamperSignatureBytes: true }).send('POST', CATALOG_SEARCH_PATH, catalogSearch);

            expect(refusal.status(), await refusal.text()).toBe(401);
        });
    });
});
