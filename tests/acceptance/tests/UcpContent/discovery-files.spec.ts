import { expect, test } from '@fixtures/AcceptanceTest';

const DISCOVERY_FILE_CONTENT_TYPES = {
    'llms.txt': 'text/plain',
    'agents.md': 'text/markdown',
    'AGENTS.md': 'text/markdown',
    '.well-known/ai-catalog.json': 'application/json',
};

const UCP_PROFILE_PATH = '/.well-known/ucp';

test.describe('Agentic discovery files @UcpContent', () => {
    test('a UCP-active channel serves every discovery file with its content type, both Markdown spellings alike', async ({ TestDataService, request }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        const bodyByFileName = new Map<string, string>();
        for (const [fileName, contentType] of Object.entries(DISCOVERY_FILE_CONTENT_TYPES)) {
            const discoveryFile = await request.get(`${ucpChannel.url}${fileName}`);
            const discoveryFileBody = await discoveryFile.text();

            expect(discoveryFile.status(), `${fileName}: ${discoveryFileBody}`).toBe(200);
            expect(discoveryFile.headers()['content-type']).toContain(contentType);
            bodyByFileName.set(fileName, discoveryFileBody);
        }

        expect(bodyByFileName.get('AGENTS.md')).toBe(bodyByFileName.get('agents.md'));
        const aiCatalog = JSON.parse(bodyByFileName.get('.well-known/ai-catalog.json') ?? '') as { entries: { url: string }[] };
        expect(aiCatalog.entries.map(entry => entry.url)).toContain(TestDataService.wellKnownUrl(ucpChannel.url));
    });

    test('switching UCP off removes the UCP sections and keeps the files', async ({ TestDataService, request }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        for (const fileName of ['llms.txt', 'agents.md']) {
            expect(await (await request.get(`${ucpChannel.url}${fileName}`)).text()).toContain(UCP_PROFILE_PATH);
        }

        const deactivationResponse = await TestDataService.saveUcpConfig(ucpChannel.salesChannel.id, { active: false });
        expect(deactivationResponse.ok(), await deactivationResponse.text()).toBeTruthy();

        for (const fileName of ['llms.txt', 'agents.md']) {
            const discoveryFileResponseWithoutUcp = await request.get(`${ucpChannel.url}${fileName}`);

            expect(discoveryFileResponseWithoutUcp.status()).toBe(200);
            expect(await discoveryFileResponseWithoutUcp.text()).not.toContain(UCP_PROFILE_PATH);
        }
    });

    test('an inactive sales channel serves none of the discovery files', async ({ TestDataService, request }) => {
        const deactivatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(deactivatedChannel.salesChannel.id);

        const salesChannelDeactivationResponse = await TestDataService.AdminApiClient.patch(`./sales-channel/${deactivatedChannel.salesChannel.id}`, { data: { active: false } });
        expect(salesChannelDeactivationResponse.ok(), await salesChannelDeactivationResponse.text()).toBeTruthy();

        for (const fileName of Object.keys(DISCOVERY_FILE_CONTENT_TYPES)) {
            const refusedFile = await request.get(`${deactivatedChannel.url}${fileName}`);
            expect(refusedFile.status(), fileName).toBeGreaterThanOrEqual(400);
            expect(refusedFile.status(), fileName).toBeLessThan(500);
        }
    });

    test('the RFC 9727 api-catalog links the UCP profile and the Store API while UCP is active', async ({ TestDataService, request }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const apiCatalogUrl = `${ucpChannel.url}.well-known/api-catalog`;

        const apiCatalogResponse = await request.get(apiCatalogUrl);
        expect(apiCatalogResponse.status()).toBe(200);
        expect(apiCatalogResponse.headers()['content-type']).toContain('application/linkset+json');
        const { linkset: [catalogEntry] } = (await apiCatalogResponse.json()) as { linkset: { 'service-meta': { href: string }[], item: { href: string }[] }[] };
        expect(catalogEntry['service-meta'].map(link => link.href)).toContain(TestDataService.wellKnownUrl(ucpChannel.url));
        expect(catalogEntry.item.map(link => link.href)).toContain(`${ucpChannel.url}store-api`);

        await TestDataService.saveUcpConfig(ucpChannel.salesChannel.id, { active: false });
        expect((await request.get(apiCatalogUrl)).status()).toBe(404);
    });
});
