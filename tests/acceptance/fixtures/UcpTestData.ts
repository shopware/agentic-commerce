import { test as base } from '@playwright/test';
import type { FixtureTypes } from '@shopware-ag/acceptance-test-suite';
import { UcpTestDataService } from '@services/UcpTestDataService';
import { agentProfileHost } from './UcpAgentProfileHost';
import type { UcpConsoleTypes } from './UcpConsole';

export interface UcpTestDataFixtureTypes {
    TestDataService: UcpTestDataService
}

const skipCleanUp = ['1', 'true'].includes(process.env.ATS_SKIP_CLEANUP ?? '');

const ATS_CLEANUP_ATTEMPTS = 2;

// MariaDB 11.6+ snapshot isolation rejects a product delete with error 1020 while other workers write products, and core does not retry it.
// The ATS asserts its first delete batch but returns the last one unchecked, so that response is checked here.
async function atsCleanUpRetryingWriteConflict(service: UcpTestDataService): Promise<void> {
    for (let attempt = 1; ; attempt++) {
        try {
            const lastDeleteBatch = await service.cleanUp();
            if (lastDeleteBatch === null || lastDeleteBatch.ok()) {
                return;
            }
            throw new Error(`ATS cleanup failed to delete its last batch: ${lastDeleteBatch.status()} ${await lastDeleteBatch.text()}`);
        }
        catch (error) {
            if (attempt === ATS_CLEANUP_ATTEMPTS) {
                throw error;
            }
        }
    }
}

/**
 * Replaces the ATS `TestDataService` with the UCP-aware subclass, cleaned up in the same order
 * SwagCommercial uses: plugin entities first, then the ATS registry.
 */
export const test = base.extend<FixtureTypes & UcpTestDataFixtureTypes, UcpConsoleTypes>({
    TestDataService: async ({ AdminApiContext, IdProvider, DefaultSalesChannel, SalesChannelBaseConfig, UcpConsole }, use) => {
        const appUrl = SalesChannelBaseConfig.appUrl ?? process.env.APP_URL ?? '';
        const service = new UcpTestDataService(AdminApiContext, IdProvider, {
            defaultSalesChannel: DefaultSalesChannel.salesChannel,
            defaultTaxId: SalesChannelBaseConfig.taxId,
            defaultCurrencyId: SalesChannelBaseConfig.defaultCurrencyId,
            defaultCategoryId: DefaultSalesChannel.salesChannel.navigationCategoryId,
            defaultLanguageId: DefaultSalesChannel.salesChannel.languageId,
            defaultCountryId: DefaultSalesChannel.salesChannel.countryId,
            defaultCustomerGroupId: DefaultSalesChannel.salesChannel.customerGroupId,
            appUrl: appUrl.replace(/\/+$/, '') + '/',
            baseConfig: SalesChannelBaseConfig,
            agentHost: agentProfileHost(),
            console: UcpConsole,
        });

        await use(service);

        if (skipCleanUp) {
            return;
        }

        // The ATS cleanup always runs, and neither failure may replace the other in the report.
        const cleanupErrors: unknown[] = [];
        for (const cleanUpStep of [() => service.cleanUpUcpEntities(), () => atsCleanUpRetryingWriteConflict(service)]) {
            try {
                await cleanUpStep();
            }
            catch (error) {
                cleanupErrors.push(error);
            }
        }
        if (cleanupErrors.length === 1) {
            throw cleanupErrors[0];
        }
        if (cleanupErrors.length > 1) {
            throw new Error(cleanupErrors.map(error => (error instanceof Error ? error.message : String(error))).join('\n\nthen:\n'));
        }
    },
});
