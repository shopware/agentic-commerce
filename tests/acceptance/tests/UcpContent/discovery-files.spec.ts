import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { CORE_SERVES_NO_UPPERCASE_AGENTS_MD, FALLBACK_DROPS_FILES_WITHOUT_UCP } from './knownBlockerRanges';

const DISCOVERY_FILE_CONTENT_TYPES = {
    'llms.txt': 'text/plain',
    'agents.md': 'text/markdown',
    '.well-known/ai-catalog.json': 'application/json',
};

const UCP_PROFILE_PATH = '/.well-known/ucp';

test.describe('Agentic discovery files @UcpContent', () => {
    test('a UCP-active channel serves every discovery file with its content type', async ({ TestDataService, request }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        for (const [fileName, contentType] of Object.entries(DISCOVERY_FILE_CONTENT_TYPES)) {
            const discoveryFile = await request.get(`${ucpChannel.url}${fileName}`);

            expect(discoveryFile.status(), `${fileName}: ${await discoveryFile.text()}`).toBe(200);
            expect(discoveryFile.headers()['content-type']).toContain(contentType);
        }
    });

    test('a UCP-active channel on a path-prefixed domain links no UCP profile from its discovery files', async ({ TestDataService, request }) => {
        const pathPrefixedUcpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(pathPrefixedUcpChannel.salesChannel.id);

        for (const fileName of Object.keys(DISCOVERY_FILE_CONTENT_TYPES)) {
            const discoveryFileBody = await (await request.get(`${pathPrefixedUcpChannel.url}${fileName}`)).text();

            expect(discoveryFileBody, `${fileName} links no profile below the path prefix`).not.toContain(UCP_PROFILE_PATH);
        }
    });

    test('/AGENTS.md serves the same document as /agents.md', async ({ InstanceMeta, TestDataService, request }) => {
        test.skip(satisfies(InstanceMeta.version, CORE_SERVES_NO_UPPERCASE_AGENTS_MD), 'known blocker D10, asserted in tests/KnownBlocked/discovery-files.spec.ts');
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        const upperCaseFileResponse = await request.get(`${ucpChannel.url}AGENTS.md`);
        const lowerCaseFileResponse = await request.get(`${ucpChannel.url}agents.md`);

        expect(upperCaseFileResponse.status(), await upperCaseFileResponse.text()).toBe(200);
        expect(upperCaseFileResponse.headers()['content-type']).toContain('text/markdown');
        expect(await upperCaseFileResponse.text()).toBe(await lowerCaseFileResponse.text());
    });

    test('switching UCP off keeps the discovery files', async ({ InstanceMeta, TestDataService, request }) => {
        test.skip(satisfies(InstanceMeta.version, FALLBACK_DROPS_FILES_WITHOUT_UCP), 'known blocker D9, asserted in tests/KnownBlocked/discovery-files.spec.ts');
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        const deactivationResponse = await TestDataService.saveUcpConfig(ucpChannel.salesChannel.id, { active: false });
        expect(deactivationResponse.ok(), await deactivationResponse.text()).toBeTruthy();

        for (const fileName of ['llms.txt', 'agents.md']) {
            const discoveryFileResponseWithoutUcp = await request.get(`${ucpChannel.url}${fileName}`);
            const discoveryFileBodyWithoutUcp = await discoveryFileResponseWithoutUcp.text();

            expect(discoveryFileResponseWithoutUcp.status()).toBe(200);
            expect(discoveryFileBodyWithoutUcp, `${fileName} still describes the channel`).toContain(ucpChannel.salesChannel.name);
        }
    });

    test('an inactive sales channel serves none of the discovery files', async ({ TestDataService, request }) => {
        const deactivatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(deactivatedChannel.salesChannel.id);

        for (const fileName of Object.keys(DISCOVERY_FILE_CONTENT_TYPES)) {
            expect((await request.get(`${deactivatedChannel.url}${fileName}`)).status(), `${fileName} before deactivation`).toBe(200);
        }

        const salesChannelDeactivationResponse = await TestDataService.AdminApiClient.patch(`./sales-channel/${deactivatedChannel.salesChannel.id}`, { data: { active: false } });
        expect(salesChannelDeactivationResponse.ok(), await salesChannelDeactivationResponse.text()).toBeTruthy();

        for (const fileName of Object.keys(DISCOVERY_FILE_CONTENT_TYPES)) {
            const refusedFile = await request.get(`${deactivatedChannel.url}${fileName}`);
            expect(refusedFile.status(), `${fileName} after deactivation`).toBeGreaterThanOrEqual(400);
            expect(refusedFile.status(), `${fileName} after deactivation`).toBeLessThan(500);
        }
    });

    test('the RFC 9727 api-catalog links the Store API while UCP is active', async ({ TestDataService, request }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const apiCatalogUrl = `${ucpChannel.url}.well-known/api-catalog`;

        const apiCatalogResponse = await request.get(apiCatalogUrl);
        expect(apiCatalogResponse.status()).toBe(200);
        expect(apiCatalogResponse.headers()['content-type']).toContain('application/linkset+json');
        const { linkset: [catalogEntry] } = (await apiCatalogResponse.json()) as { linkset: { 'service-meta'?: { href: string }[], item: { href: string }[] }[] };
        expect(catalogEntry['service-meta'], 'a path-prefixed domain links no UCP profile').toBeUndefined();
        expect(catalogEntry.item.map(link => link.href)).toContain(`${ucpChannel.url}store-api`);

        const deactivationResponse = await TestDataService.saveUcpConfig(ucpChannel.salesChannel.id, { active: false });
        expect(deactivationResponse.ok(), await deactivationResponse.text()).toBeTruthy();
        expect((await request.get(apiCatalogUrl)).status()).toBe(404);
    });
});
