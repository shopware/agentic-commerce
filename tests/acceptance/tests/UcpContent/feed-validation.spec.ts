import { expect, test } from '@fixtures/AcceptanceTest';
import { readFeedTemplates } from '@services/pluginSource';
import { feedProviders } from './feedProviders';

for (const [providerName, expectations] of Object.entries(feedProviders)) {
    test.describe(`${providerName} feed validation @UcpContent`, () => {
        test('an invalid row blocks the export and Validate reports it', async ({ TestDataService, request }) => {
            const template = (await readFeedTemplates()).find(candidate => candidate.providerName === providerName);
            expect(template, `No shipped feed template for provider ${providerName}.`).toBeDefined();
            const invalidSetup = await expectations.breakValidation(TestDataService);

            const feed = await TestDataService.createProductFeed(template!, invalidSetup.productIds, { includeVariants: invalidSetup.includeVariants });
            await TestDataService.saveFeedSettings(feed.feedChannel.id, { ...expectations.settings, ...invalidSetup.settings });

            const refusedExport = await request.get(feed.exportUrl);
            expect(refusedExport.status()).toBeGreaterThanOrEqual(400);

            const productExportLookup = await TestDataService.AdminApiClient.get(`./product-export/${feed.productExportId}`);
            const { data: productExport } = (await productExportLookup.json()) as { data: Record<string, unknown> };
            const validationOutcome = await TestDataService.AdminApiClient.post('./_action/product-export/validate', { data: productExport });

            expect(validationOutcome.status(), await validationOutcome.text()).toBe(200);
            const { errors } = (await validationOutcome.json()) as { errors: unknown[] };
            expect(JSON.stringify(errors)).toContain(invalidSetup.rejectedField);
        });
    });
}
