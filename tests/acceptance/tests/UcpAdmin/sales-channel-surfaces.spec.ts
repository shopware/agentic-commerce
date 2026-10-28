import { expect, test } from '@fixtures/AcceptanceTest';
import type { Locator, Page, Request } from '@playwright/test';
import { FEED_SALES_CHANNEL_TYPE_ID } from '@services/UcpTestDataService';
import { goToAdminRoute } from '@page-objects/adminRoute';
import { AgenticCommerceFeedDetail } from '@page-objects/AgenticCommerceFeedDetail';

const listRoute = (term: string) => `/sw/sales/channel/list?term=${encodeURIComponent(term)}`;

/** The detail page's tab link to one of its routes, identified by the route rather than the label. */
const tabLink = (page: Page, salesChannelId: string, view: string) => page.locator(`a[href$="/detail/${salesChannelId}/${view}"]`);

/**
 * The id the Administration generated for a new sales channel, read from its create request: a
 * sync upsert on current lanes, a REST POST on older ones. Null for any other request.
 */
function createdSalesChannelId(request: Request): string | null {
    if (request.method() !== 'POST') {
        return null;
    }

    const path = new URL(request.url()).pathname;
    if (path.endsWith('/api/sales-channel')) {
        return (request.postDataJSON() as { id: string }).id;
    }
    if (path.endsWith('/api/_action/sync')) {
        const operations = request.postDataJSON() as { entity: string, action: string, payload: { id: string }[] }[];

        return operations.find(operation => operation.entity === 'sales_channel' && operation.action === 'upsert')?.payload[0]?.id ?? null;
    }

    return null;
}

async function chooseEntity(field: Locator, search: string): Promise<void> {
    await field.locator('.sw-select__selection').click();
    await field.locator('input').fill(search);
    // The result list renders in a popover outside the field.
    await field.page().locator('.sw-select-result').filter({ hasText: search }).first().click();
}

test.describe('Sales channel surfaces of the plugin @UcpAdmin', () => {
    test('a ucp.viewer sees which channels are exposed via UCP in the list', async ({ TestDataService, UcpAclUsers }) => {
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

    test('a user without ucp.viewer sees the list without the UCP column', async ({ TestDataService, UcpAclUsers }) => {
        const exposed = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(exposed.salesChannel.id);
        const page = await UcpAclUsers.asSalesChannelViewer();

        await goToAdminRoute(page, listRoute(exposed.salesChannel.name));
        const row = page.locator('.sw-data-grid__row').filter({ hasText: exposed.salesChannel.name });
        await expect(row).toBeVisible();
        await expect(page.locator('[data-testid^="ac-sales-channel-list__ucp-status"]')).toHaveCount(0);
    });

    test('an Agentic Commerce channel created from the modal is saved with the prefilled feed export', async ({ TestDataService, AdminPage, DefaultSalesChannel }) => {
        const productStream = await TestDataService.createProductStream();
        const name = `${TestDataService.namePrefix}UCP-created-${Date.now()}`;
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        let salesChannelId = '';

        await test.step('the type is offered with its badge in the create modal', async () => {
            await goToAdminRoute(AdminPage, listRoute(name));
            // The list's only smart-bar action opens the create modal.
            await AdminPage.locator('div.sw-page.sw-sales-channel-list .smart-bar__actions button').click();

            const addAgenticCommerce = AdminPage.locator(`.sw-sales-channel-modal__add-channel-action[data-analytics-sales-channel-type-id="${FEED_SALES_CHANNEL_TYPE_ID}"]`);
            await expect(addAgenticCommerce).toBeVisible();
            await expect(AdminPage.getByTestId('ac-sales-channel-modal__agentic-badge')).toHaveCount(1);
            await expect(AdminPage.locator('.sw-grid-row').filter({ has: addAgenticCommerce })
                .getByTestId('ac-sales-channel-modal__agentic-badge'), 'the badge marks the Agentic Commerce type').toBeVisible();
            await addAgenticCommerce.click();
            await AdminPage.waitForURL(new RegExp(`/sw/sales/channel/create/${FEED_SALES_CHANNEL_TYPE_ID}/`));
            await expect(feed.returnPolicyUrl).toBeVisible();
            await feed.expectLoaded();
        });

        await test.step('the merchant fills in what has no default and saves', async () => {
            await AdminPage.locator('.sw-field--salesChannel-name input').fill(name);
            await feed.fillReturnPolicyUrl('shop.example/returns');
            await chooseEntity(AdminPage.locator('.sw-sales-channel-detail__product-comparison-storefront'), DefaultSalesChannel.salesChannel.name);
            await chooseEntity(AdminPage.locator('.sw-sales-channel-detail__product-comparison-domain'), new URL(DefaultSalesChannel.url).host);
            await chooseEntity(AdminPage.locator('.sw-sales-channel-detail__product-comparison-product-stream'), productStream.name);

            const creation = AdminPage.waitForRequest(request => createdSalesChannelId(request) !== null);
            await AdminPage.locator('.sw-sales-channel-detail__save-action').click();

            // The Administration generates the id, so the channel is registered for cleanup before its save completes.
            salesChannelId = createdSalesChannelId(await creation)!;
            TestDataService.adoptSalesChannel(salesChannelId);
            await AdminPage.waitForURL(url => url.hash.includes(`/sw/sales/channel/detail/${salesChannelId}/`));
        });

        await test.step('the stored export carries the prefilled OpenAI defaults', async () => {
            const { productExports } = await TestDataService.getSalesChannelReadback(salesChannelId);
            expect(productExports).toHaveLength(1);
            expect(productExports[0]).toMatchObject({ provider: 'open-ai', fileFormat: 'jsonl' });
            expect(productExports[0].fileName).toMatch(/^agentic-commerce-[0-9a-f]{32}\.jsonl$/);
        });
    });

    test('a product-feed channel has no Agentic Commerce tab, a storefront channel has', async ({ TestDataService, AdminPage }) => {
        const feed = await TestDataService.createFeedSalesChannelWithExport();
        const storefront = await TestDataService.createStorefrontSalesChannel();

        await goToAdminRoute(AdminPage, `/sw/sales/channel/detail/${storefront.salesChannel.id}/base`);
        await expect(tabLink(AdminPage, storefront.salesChannel.id, 'agentic-commerce')).toBeVisible();

        await goToAdminRoute(AdminPage, `/sw/sales/channel/detail/${feed.salesChannel.id}/base`);
        await expect(tabLink(AdminPage, feed.salesChannel.id, 'agentic-commerce-integration')).toBeVisible();
        await expect(tabLink(AdminPage, feed.salesChannel.id, 'agentic-commerce')).toHaveCount(0);
    });
});
