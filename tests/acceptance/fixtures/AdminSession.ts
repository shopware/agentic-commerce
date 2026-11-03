import { test as base } from '@playwright/test';
import { createNewAdminPageContext, loginToAdministration } from '@shopware-ag/acceptance-test-suite';
import type { FixtureTypes, User } from '@shopware-ag/acceptance-test-suite';
import { createUserRetryingTokenConflicts } from '@services/UcpTestDataService';

type WorkerTypes = Pick<FixtureTypes, 'AdminSession' | 'AdminApiContext' | 'IdProvider' | 'SalesChannelBaseConfig'>;

/**
 * The ATS `AdminSession` (acceptance-test-suite 12.20.0), with its user created through
 * `createUserRetryingTokenConflicts()`: the ATS asserts its one `POST /api/user`, so core's refresh
 * token conflict (MariaDB error 1020) failed the first test of a worker.
 * Remove this override once the ATS release with https://github.com/shopware/acceptance-test-suite/pull/739 is used.
 */
export const test = base.extend<NonNullable<unknown>, WorkerTypes>({
    AdminSession: [
        async ({ IdProvider, AdminApiContext, SalesChannelBaseConfig, browser }, use) => {
            const adminUser = await createUserRetryingTokenConflicts(AdminApiContext, (): User => {
                const { id, uuid } = IdProvider.getIdPair();

                return {
                    id: uuid,
                    username: `admin_${id}`,
                    firstName: `${id} admin`,
                    lastName: `${id} admin`,
                    localeId: SalesChannelBaseConfig.currentLocaleId,
                    email: `admin_${id}@example.com`,
                    timeZone: 'Europe/Berlin',
                    password: 'shopware',
                    admin: true,
                };
            });

            const loginPage = await loginToAdministration(await createNewAdminPageContext(browser, SalesChannelBaseConfig), adminUser, AdminApiContext);
            const context = loginPage.context();
            await loginPage.close();

            await use({ context, user: adminUser });

            await context.close();
            await AdminApiContext.delete(`user/${adminUser.id}`);
        },
        { scope: 'worker' },
    ],
});
