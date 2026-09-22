import { test as base } from '@playwright/test';
import type { FixtureTypes } from '@shopware-ag/acceptance-test-suite';
import { UcpTestDataService } from '@services/UcpTestDataService';
import { agentProfileHost } from './UcpAgentProfileHost';
import type { UcpConsoleTypes } from './UcpConsole';

export interface UcpTestDataFixtureTypes {
    TestDataService: UcpTestDataService
}

const skipCleanUp = ['1', 'true'].includes(process.env.ATS_SKIP_CLEANUP ?? '');

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

        if (!skipCleanUp) {
            await service.cleanUpUcpEntities();
            await service.cleanUp();
        }
    },
});
