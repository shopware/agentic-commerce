import { expect, test } from '@fixtures/AcceptanceTest';
import type { Page } from '@playwright/test';
import { UcpSalesChannelDetail } from '@page-objects/UcpSalesChannelDetail';
import { goToAdminRoute } from '@page-objects/adminRoute';
import type { UcpConfigPayload } from '../../services/UcpTestDataService';

const ucpApiPath = (salesChannelId: string, suffix = '') => `/api/_admin/ucp/sales-channels/${salesChannelId}${suffix}`;
const listRoute = (term: string) => `/sw/sales/channel/list?term=${encodeURIComponent(term)}`;
const insightsTab = (page: Page, salesChannelId: string) => page.locator([
    `a[href$="/detail/${salesChannelId}/product-export-insights"]`,
    `a[href$="/detail/${salesChannelId}/agentic-commerce-statistics"]`,
].join(', '));
const insightsCards = (page: Page) => page.locator([
    '.sw-chart-card',
    '.sw-sales-channel-detail-agentic-commerce-statistics__orders',
    '.sw-sales-channel-detail-agentic-commerce-statistics__customers',
    '.sw-sales-channel-detail-agentic-commerce-statistics__turnover',
].join(', '));

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

            const readable = await directConfigGet(page, salesChannel.id);
            expect(readable.status(), await readable.text()).toBe(200);
            expect(((await readable.json()) as { data: UcpConfigPayload }).data).toMatchObject({ ...before });

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
        const requests: Array<{ method: string, pathname: string }> = [];
        editor.page.on('request', (request) => {
            requests.push({
                method: request.method(),
                pathname: new URL(request.url()).pathname,
            });
        });
        await detail.saveUcpConfig(salesChannel.id);
        expect((await TestDataService.getUcpConfig(salesChannel.id)).enabledCapabilities).not.toContain('discount');
        expect(requests.filter(({ method, pathname }) =>
            method !== 'GET'
            && (pathname.endsWith('/api/sales-channel') || pathname.endsWith('/api/_action/sync')),
        )).toEqual([]);
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

    test('Insights cards require ucp.viewer while core card permissions stay the same', async ({ TestDataService, UcpAclUsers }) => {
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport();
        const coreCardRoles = ['order.viewer', 'customer.viewer'];
        const withoutUcpViewer = await UcpAclUsers.asWithCoreRoles(coreCardRoles);
        const withUcpViewer = await UcpAclUsers.asWithCoreRoles([...coreCardRoles, 'ucp.viewer']);

        await goToAdminRoute(withUcpViewer, `/sw/sales/channel/detail/${salesChannel.id}/base`);
        const insightTab = insightsTab(withUcpViewer, salesChannel.id).first();
        await expect(insightTab).toBeVisible();
        await insightTab.click();
        await expect(withUcpViewer).toHaveURL(/product-export-insights|agentic-commerce-statistics/);
        const insightsRoute = new URL(withUcpViewer.url()).hash.slice(1);
        await expect(insightsCards(withUcpViewer).first()).toBeVisible();

        await goToAdminRoute(withoutUcpViewer, `/sw/sales/channel/detail/${salesChannel.id}/base`);
        await expect(insightsTab(withoutUcpViewer, salesChannel.id)).toHaveCount(0);
        const statisticsSearchPaths = new Set([
            '/api/search/order',
            '/api/search/customer',
            '/api/search/sales-channel-tracking-order',
            '/api/search/sales-channel-tracking-customer',
        ]);
        const requests: Array<{ method: string, pathname: string }> = [];
        withoutUcpViewer.on('request', (request) => {
            requests.push({
                method: request.method(),
                pathname: new URL(request.url()).pathname,
            });
        });

        await goToAdminRoute(withoutUcpViewer, insightsRoute);
        await expect(insightsCards(withoutUcpViewer)).toHaveCount(0);
        expect(requests.filter(({ pathname }) => statisticsSearchPaths.has(pathname))).toEqual([]);
    });

    test('a ucp.viewer sees exposed and disabled channels in the UCP list column', async ({ TestDataService, UcpAclUsers }) => {
        const exposed = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(exposed.salesChannel.id);
        const notExposed = await TestDataService.createStorefrontSalesChannel();
        const { page } = await UcpAclUsers.as('ucp.viewer');
        const rowOf = (name: string) => page.locator('.sw-data-grid__row').filter({ hasText: name });

        await goToAdminRoute(page, listRoute(exposed.salesChannel.name));
        await expect(rowOf(exposed.salesChannel.name).getByTestId('ac-sales-channel-list__ucp-status--exposed')).toBeVisible();
        await goToAdminRoute(page, listRoute(notExposed.salesChannel.name));
        await expect(rowOf(notExposed.salesChannel.name).getByTestId('ac-sales-channel-list__ucp-status--off')).toBeVisible();
        await expect(rowOf(notExposed.salesChannel.name).getByTestId('ac-sales-channel-list__ucp-status--exposed')).toHaveCount(0);
    });

    test('users without ucp.viewer do not see the UCP list column', async ({ TestDataService, UcpAclUsers }) => {
        const exposed = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(exposed.salesChannel.id);
        const page = await UcpAclUsers.asSalesChannelViewer();

        await goToAdminRoute(page, listRoute(exposed.salesChannel.name));
        const row = page.locator('.sw-data-grid__row').filter({ hasText: exposed.salesChannel.name });
        await expect(row).toBeVisible();
        await expect(page.locator('[data-testid^="ac-sales-channel-list__ucp-status"]')).toHaveCount(0);
    });
});
