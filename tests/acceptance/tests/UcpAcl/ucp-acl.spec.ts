import { expect, test } from '@fixtures/AcceptanceTest';
import type { Page } from '@playwright/test';
import { UcpSalesChannelDetail } from '@page-objects/UcpSalesChannelDetail';
import { goToAdminRoute } from '@page-objects/adminRoute';

const ucpApiPath = (salesChannelId: string, suffix = '') => `/api/_admin/ucp/sales-channels/${salesChannelId}${suffix}`;
const listRoute = (term: string) => `/sw/sales/channel/list?term=${encodeURIComponent(term)}`;

type AdminGlobal = typeof globalThis & {
    Shopware: {
        Service(name: string): { getToken(): string }
    }
};

async function authenticatedApiUrl(page: Page, path: string): Promise<{ url: string, headers: Record<string, string> }> {
    const token = await page.evaluate(() => (globalThis as AdminGlobal).Shopware.Service('loginService').getToken());
    const appUrl = process.env.APP_URL;
    if (!appUrl) {
        throw new Error('APP_URL must be set for authenticated API requests.');
    }

    return {
        url: new URL(path, appUrl).toString(),
        headers: { Authorization: `Bearer ${token}` },
    };
}

async function directConfigPut(page: Page, salesChannelId: string, config: Record<string, unknown>) {
    const auth = await authenticatedApiUrl(page, ucpApiPath(salesChannelId, '/config'));

    return page.request.put(auth.url, { headers: auth.headers, data: config });
}

async function directConfigGet(page: Page, salesChannelId: string) {
    const auth = await authenticatedApiUrl(page, ucpApiPath(salesChannelId, '/config'));

    return page.request.get(auth.url, { headers: auth.headers });
}

test.describe('UCP Administration ACL @UcpAcl', () => {
    for (const role of ['ucp.viewer', 'ucp.key_rotator'] as const) {
        test(`${role} can view the configuration but cannot edit or save it`, async ({ TestDataService, UcpAclUsers }) => {
            const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
            await TestDataService.activateUcp(salesChannel.id);
            const before = await TestDataService.getUcpConfig(salesChannel.id);
            const { page } = await UcpAclUsers.as(role);
            const detail = new UcpSalesChannelDetail(page);

            await detail.openAgenticCommerceTab(salesChannel.id);

            await expect(detail.readOnlyBanner).toBeVisible();
            await expect(detail.exposeSwitch).toBeDisabled();
            await expect(page.locator('[data-testid^="ac-ucp__capability--"] input[type="checkbox"]').first()).toBeDisabled();
            await expect(page.locator('[data-testid^="ac-ucp__transport--"] input[type="checkbox"]').first()).toBeDisabled();
            await expect(detail.saveButton).toBeDisabled();

            const denied = await directConfigPut(page, salesChannel.id, { enabledCapabilities: ['catalog'] });
            expect(denied.status(), await denied.text()).toBe(403);
            expect(await TestDataService.getUcpConfig(salesChannel.id)).toEqual(before);
        });
    }

    test('ucp.editor saves only UCP settings without sales_channel.editor', async ({ TestDataService, UcpAclUsers }) => {
        const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(salesChannel.id);
        const editor = await UcpAclUsers.as('ucp.editor');
        expect(editor.privileges).not.toContain('sales_channel:update');

        const detail = new UcpSalesChannelDetail(editor.page);
        await detail.openAgenticCommerceTab(salesChannel.id);
        await expect(detail.readOnlyBanner).toBeHidden();
        await expect(detail.saveButton).toBeEnabled();

        await detail.capability('discount').uncheck();
        const corePersistenceRequests: string[] = [];
        editor.page.on('request', (request) => {
            const pathname = new URL(request.url()).pathname;
            if (request.method() !== 'GET' && (pathname.endsWith('/api/sales-channel') || pathname.endsWith('/api/_action/sync'))) {
                corePersistenceRequests.push(`${request.method()} ${pathname}`);
            }
        });
        await detail.saveUcpConfig(salesChannel.id);
        expect((await TestDataService.getUcpConfig(salesChannel.id)).enabledCapabilities).not.toContain('discount');
        expect(corePersistenceRequests).toEqual([]);
        await detail.reload(salesChannel.id);
        await expect(detail.capability('discount')).not.toBeChecked();

        const directWrite = await directConfigPut(editor.page, salesChannel.id, { enabledTransports: ['rest', 'embedded'] });
        expect(directWrite.status(), await directWrite.text()).toBe(200);
        expect((await TestDataService.getUcpConfig(salesChannel.id)).enabledTransports).toEqual(['rest', 'embedded']);
    });

    test('a user without a UCP role has no UCP list column or tab and cannot access configuration', async ({ TestDataService, UcpAclUsers }) => {
        const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(salesChannel.id);
        const page = await UcpAclUsers.asSalesChannelViewer();

        await goToAdminRoute(page, listRoute(salesChannel.name));
        const row = page.locator('.sw-data-grid__row').filter({ hasText: salesChannel.name });
        await expect(row).toBeVisible();
        await expect(page.locator('[data-testid^="ac-sales-channel-list__ucp-status"]')).toHaveCount(0);

        await goToAdminRoute(page, `/sw/sales/channel/detail/${salesChannel.id}/base`);
        await expect(page.locator(`a[href$="/detail/${salesChannel.id}/agentic-commerce"]`)).toHaveCount(0);

        const read = await directConfigGet(page, salesChannel.id);
        expect(read.status(), await read.text()).toBe(403);
        const write = await directConfigPut(page, salesChannel.id, { active: false });
        expect(write.status(), await write.text()).toBe(403);
    });
});
