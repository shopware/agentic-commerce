import { test as base } from '@playwright/test';
import type { Page } from '@playwright/test';
import { createNewAdminPageContext, loginToAdministration } from '@shopware-ag/acceptance-test-suite';
import type { FixtureTypes, User } from '@shopware-ag/acceptance-test-suite';
import { readAdminPrivilegeMapping, resolveRole } from '@services/pluginSource';
import type { UcpTestDataFixtureTypes } from './UcpTestData';

export const UCP_ROLES = ['ucp.viewer', 'ucp.editor', 'ucp.key_rotator'] as const;
export type UcpRole = typeof UCP_ROLES[number];

/**
 * Core's `sales_channel.viewer` privileges (`sw-sales-channel/acl/index.js` on trunk), flattened.
 * Without them a user cannot open the sales channel the Agentic Commerce tab sits on.
 */
const SALES_CHANNEL_VIEWER_PRIVILEGES = [
    'sales_channel:read',
    'sales_channel_type:read',
    'payment_method:read',
    'shipping_method:read',
    'country:read',
    'currency:read',
    'sales_channel_domain:read',
    'sales_channel_file:read',
    'snippet_set:read',
    'sales_channel_analytics:read',
    'product_export:read',
    'theme:read',
    'custom_field_set:read',
    'custom_field:read',
    'custom_field_set_relation:read',
    'category:read',
    'customer_group:read',
    'media:read',
    'media_folder:read',
    'media_default_folder:read',
    'product:read',
    'product_stream:read',
    'product_visibility:read',
    'property_group:read',
    'property_group_option:read',
    'user_config:read',
    'user_config:create',
    'user_config:update',
    'system_config:read',
    'sales_channel_tracking_order:read',
    'sales_channel_tracking_customer:read',
    'order:read',
    'order_transaction:read',
    'state_machine_state:read',
];

export interface UcpAclUser {
    role: UcpRole
    user: User
    privileges: string[]
    page: Page
}

export interface UcpAclUsers {
    /** One Administration user per UCP role, created and logged in on first use. */
    as(role: UcpRole): Promise<UcpAclUser>
}

export interface UcpAclUsersTypes {
    UcpAclUsers: UcpAclUsers
}

export const test = base.extend<FixtureTypes & UcpTestDataFixtureTypes & UcpAclUsersTypes>({
    UcpAclUsers: async ({ TestDataService, AdminApiContext, SalesChannelBaseConfig, browser }, use) => {
        const mapping = await readAdminPrivilegeMapping();
        const users = new Map<UcpRole, Promise<UcpAclUser>>();

        const create = async (role: UcpRole): Promise<UcpAclUser> => {
            const resolved = resolveRole(mapping, role);
            const privileges = [...new Set([
                ...(TestDataService.getBasicAclRoleStruct().privileges ?? []),
                ...SALES_CHANNEL_VIEWER_PRIVILEGES,
                'sales_channel.viewer',
                ...resolved.keys,
                ...resolved.privileges,
            ])];

            const aclRole = await TestDataService.createAclRole({ name: `${TestDataService.namePrefix}${role}-${aclRoleSuffix()}`, privileges });
            const user = await TestDataService.createUser({ admin: false });
            await TestDataService.assignAclRoleUser(aclRole.id, user.id);

            const page = await loginToAdministration(await createNewAdminPageContext(browser, SalesChannelBaseConfig), user, AdminApiContext);

            return { role, user, privileges, page };
        };

        await use({
            as: (role) => {
                let pending = users.get(role);
                if (pending === undefined) {
                    pending = create(role);
                    users.set(role, pending);
                }

                return pending;
            },
        });

        for (const pending of users.values()) {
            const { page } = await pending.catch(() => ({ page: null }));
            await page?.context().close();
        }
    },
});

function aclRoleSuffix(): string {
    return Math.random().toString(36).slice(2, 8);
}
