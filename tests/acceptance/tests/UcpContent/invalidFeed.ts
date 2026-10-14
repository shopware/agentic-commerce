import { expect } from '@playwright/test';
import { readFeedTemplates } from '@services/pluginSource';
import type { UcpTestDataService } from '@services/UcpTestDataService';
import type { FeedProviderExpectations } from './feedProviders';

export async function createInvalidFeed(providerName: string, expectations: FeedProviderExpectations, testData: UcpTestDataService) {
    const template = (await readFeedTemplates()).find(candidate => candidate.providerName === providerName);
    expect(template, `No shipped feed template for provider ${providerName}.`).toBeDefined();
    const brokenFeedSetup = await expectations.breakValidation(testData);

    const feed = await testData.createProductFeed(template!, brokenFeedSetup.productIds, { includeVariants: brokenFeedSetup.includeVariants });
    await testData.saveFeedSettings(feed.feedChannel.id, { ...expectations.settings, ...brokenFeedSetup.settings });

    return { feed, rejectedField: brokenFeedSetup.rejectedField };
}

export async function validateStoredExport(testData: UcpTestDataService, productExportId: string) {
    const storedProductExportResponse = await testData.AdminApiClient.get(`./product-export/${productExportId}`);
    const { data: storedProductExport } = (await storedProductExportResponse.json()) as { data: Record<string, unknown> };

    return testData.AdminApiClient.post('./_action/product-export/validate', { data: storedProductExport });
}
