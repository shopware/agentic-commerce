import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { feedProviders } from './feedProviders';
import { createInvalidFeed, validateStoredExport } from './invalidFeed';
import { VALIDATE_SKIPS_PROVIDER_VALIDATORS } from './knownBlockerRanges';

for (const [providerName, expectations] of Object.entries(feedProviders)) {
    test.describe(`${providerName} feed validation @UcpContent`, () => {
        test('an invalid row blocks the export', async ({ TestDataService, request }) => {
            const { feed } = await createInvalidFeed(providerName, expectations, TestDataService);

            const refusedExport = await request.get(feed.exportUrl);

            expect(refusedExport.status()).toBe(500);
            const { errors } = (await refusedExport.json()) as { errors: { code: string }[] };
            expect(errors.map(error => error.code)).toContain('CONTENT__PRODUCT_EXPORT_INVALID_CONTENT');
        });

        test('Validate reports the rejected field', async ({ InstanceMeta, TestDataService }) => {
            test.skip(satisfies(InstanceMeta.version, VALIDATE_SKIPS_PROVIDER_VALIDATORS), 'known blocker D11, asserted in tests/KnownBlocked/feed-validation.spec.ts');
            const { feed, rejectedField } = await createInvalidFeed(providerName, expectations, TestDataService);

            const exportValidation = await validateStoredExport(TestDataService, feed.productExportId);

            expect(exportValidation.status(), await exportValidation.text()).toBe(200);
            const { errors } = (await exportValidation.json()) as { errors: unknown[] };
            expect(JSON.stringify(errors)).toContain(rejectedField);
        });
    });
}
