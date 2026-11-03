import { test as base } from '@playwright/test';
import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';
import { createNewAdminPageContext, loginToAdministration } from '@shopware-ag/acceptance-test-suite';
import type { FixtureTypes, User } from '@shopware-ag/acceptance-test-suite';
import { readAdminPrivilegeMapping, resolveRole } from '@services/pluginSource';
import type { UcpTestDataFixtureTypes } from './UcpTestData';

export const UCP_ROLES = ['ucp.viewer', 'ucp.editor', 'ucp.key_rotator'] as const;
export type UcpRole = typeof UCP_ROLES[number];

export interface UcpAclUser {
    role: UcpRole
    user: User
    privileges: string[]
    page: Page
}

export interface UcpAclUsers {
    /** One Administration user per UCP role, created and logged in on first use. */
    as(role: UcpRole): Promise<UcpAclUser>

    /**
     * A full admin of the test's own, logged in. The worker's `AdminPage` user is shared by every
     * test of the worker and its retries, so state the Administration stores per user, such as list
     * filters, would leak between them.
     */
    asFreshAdmin(): Promise<Page>

    /** A non-admin user with only core's `sales_channel.viewer` role, logged in. */
    asSalesChannelViewer(): Promise<Page>
}

export interface UcpAclUsersTypes {
    UcpAclUsers: UcpAclUsers
}

export const test = base.extend<FixtureTypes & UcpTestDataFixtureTypes & UcpAclUsersTypes>({
    // Not the worker's AdminSession: as a dependency it makes every worker create its admin user up
    // front, also for tests that ask for no role user.
    UcpAclUsers: async ({ TestDataService, AdminApiContext, SalesChannelBaseConfig, browser }, use) => {
        const mapping = await readAdminPrivilegeMapping();
        const users = new Map<UcpRole, Promise<UcpAclUser>>();
        const otherPages: Promise<Page>[] = [];

        const freshAdmin = (): Promise<Page> => {
            const pending = TestDataService.createUserRetryingTokenConflicts({ admin: true })
                .then(async (user) => {
                    const loginPage = await createNewAdminPageContext(browser, SalesChannelBaseConfig);
                    await withoutUpdateNotification(loginPage);

                    return loginToAdministration(loginPage, user, AdminApiContext);
                });
            otherPages.push(pending);

            return pending;
        };

        // Created on first use, so a test that asks for no role user logs in no extra admin.
        let privilegesPage: Promise<Page> | undefined;

        /**
         * What the role editor stores for these role keys: the running Administration resolves their
         * dependencies, including privileges other plugins add to core roles such as
         * `sales_channel.viewer`.
         */
        const grantedPrivileges = async (roleKeys: string[]): Promise<string[]> => {
            privilegesPage ??= freshAdmin();
            const page = await privilegesPage;
            await expect(page.locator('.sw-admin-menu')).toBeVisible();

            return page.evaluate<string[]>(`Shopware.Service('privileges').getPrivilegesForAdminPrivilegeKeys(${JSON.stringify(roleKeys)})`);
        };

        const createUser = async (name: string, roleKeys: string[]): Promise<{ user: User, privileges: string[], page: Page }> => {
            const privileges = [...new Set([
                ...(TestDataService.getBasicAclRoleStruct().privileges ?? []),
                ...await grantedPrivileges(roleKeys),
            ])];

            const aclRole = await TestDataService.createAclRole({ name: `${TestDataService.namePrefix}${name}-${aclRoleSuffix()}`, privileges });
            const user = await TestDataService.createUserRetryingTokenConflicts({ admin: false });
            await TestDataService.assignAclRoleUser(aclRole.id, user.id);

            const page = await loginToAdministration(await createNewAdminPageContext(browser, SalesChannelBaseConfig), user, AdminApiContext);

            return { user, privileges, page };
        };

        const create = async (role: UcpRole): Promise<UcpAclUser> => ({
            role,
            ...await createUser(role, ['sales_channel.viewer', ...resolveRole(mapping, role).keys]),
        });

        await use({
            as: (role) => {
                let pending = users.get(role);
                if (pending === undefined) {
                    pending = create(role);
                    users.set(role, pending);
                }

                return pending;
            },
            asFreshAdmin: freshAdmin,
            asSalesChannelViewer: () => {
                const pending = createUser('sales_channel.viewer', ['sales_channel.viewer']).then(({ page }) => page);
                otherPages.push(pending);

                return pending;
            },
        });

        const pages = [...[...users.values()].map(pending => pending.then(({ page }) => page)), ...otherPages];
        for (const pending of pages) {
            const page = await pending.catch(() => null);
            await page?.context().close();
        }
    },
});

/**
 * Core checks for a newer Shopware version when a user with `system.core_update` logs in and, when
 * there is one, shows a notification that covers the sidebar for the rest of the test. Answering the
 * check with no version keeps that independent of what has been released.
 */
async function withoutUpdateNotification(page: Page): Promise<void> {
    await page.context().route('**/api/_action/update/check', route => route.fulfill({ json: {} }));
}

function aclRoleSuffix(): string {
    return Math.random().toString(36).slice(2, 8);
}
