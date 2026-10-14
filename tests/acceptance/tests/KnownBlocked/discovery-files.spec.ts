import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { CORE_SERVES_NO_UPPERCASE_AGENTS_MD, FALLBACK_DROPS_FILES_WITHOUT_UCP } from '../UcpContent/knownBlockerRanges';

test.describe('Discovery files without UCP @UcpKnownBlocked @UcpBlockedByD9', () => {
    test('switching UCP off keeps the files on the plugin fallback', async ({ InstanceMeta, TestDataService, request }) => {
        test.skip(!satisfies(InstanceMeta.version, FALLBACK_DROPS_FILES_WITHOUT_UCP), 'D9 applies only below 6.7.12.0; tests/UcpContent/discovery-files.spec.ts covers newer versions');
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        const deactivationResponse = await TestDataService.saveUcpConfig(ucpChannel.salesChannel.id, { active: false });
        expect(deactivationResponse.ok(), await deactivationResponse.text()).toBeTruthy();

        for (const fileName of ['llms.txt', 'agents.md']) {
            const discoveryFileResponseWithoutUcp = await request.get(`${ucpChannel.url}${fileName}`);

            expect(discoveryFileResponseWithoutUcp.status(), fileName).toBe(200);
            expect(await discoveryFileResponseWithoutUcp.text()).toContain(ucpChannel.salesChannel.name);
        }
    });
});

test.describe('Upper-case AGENTS.md @UcpKnownBlocked @UcpBlockedByD10', () => {
    test('/AGENTS.md serves the same document as /agents.md on 6.7.12.x', async ({ InstanceMeta, TestDataService, request }) => {
        test.skip(!satisfies(InstanceMeta.version, CORE_SERVES_NO_UPPERCASE_AGENTS_MD), 'D10 applies only to 6.7.12.x; tests/UcpContent/discovery-files.spec.ts covers the other versions');
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        const upperCaseFileResponse = await request.get(`${ucpChannel.url}AGENTS.md`);
        const lowerCaseFileResponse = await request.get(`${ucpChannel.url}agents.md`);

        expect(upperCaseFileResponse.status(), await upperCaseFileResponse.text()).toBe(200);
        expect(await upperCaseFileResponse.text()).toBe(await lowerCaseFileResponse.text());
    });
});
