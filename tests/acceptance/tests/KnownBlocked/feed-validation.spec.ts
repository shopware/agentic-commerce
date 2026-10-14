import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { feedProviders } from '../UcpContent/feedProviders';
import { createInvalidFeed, validateStoredExport } from '../UcpContent/invalidFeed';
import { VALIDATE_SKIPS_PROVIDER_VALIDATORS } from '../UcpContent/knownBlockerRanges';

for (const [providerName, expectations] of Object.entries(feedProviders)) {
    test.describe(`${providerName} Validate before 6.7.10.0 @UcpKnownBlocked @UcpBlockedByD11`, () => {
        test('Validate reports the rejected field', async ({ InstanceMeta, TestDataService }) => {
            test.skip(!satisfies(InstanceMeta.version, VALIDATE_SKIPS_PROVIDER_VALIDATORS), 'D11 applies only below 6.7.10.0; tests/UcpContent/feed-validation.spec.ts covers newer versions');
            const { feed, rejectedField } = await createInvalidFeed(providerName, expectations, TestDataService);

            const exportValidation = await validateStoredExport(TestDataService, feed.productExportId);

            expect(exportValidation.status(), await exportValidation.text()).toBe(200);
            const { errors } = (await exportValidation.json()) as { errors: unknown[] };
            expect(JSON.stringify(errors)).toContain(rejectedField);
        });
    });
}
