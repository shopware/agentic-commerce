import { expect, test } from '@fixtures/AcceptanceTest';
import type { Page, Response } from '@playwright/test';
import { goToAdminRoute } from '@page-objects/adminRoute';
import { randomUUID } from 'node:crypto';

/** A list search filtering on the feed channel; other searches of the list may run meanwhile. */
function isFilteredSearch(response: Response, entity: 'order' | 'customer', feedId: string): boolean {
    const criteria = response.request().postData() ?? '';

    return response.request().method() === 'POST'
      && new URL(response.url()).pathname.endsWith(`/api/search/${entity}`)
      && criteria.includes('salesChannelTracking.salesChannelId')
      && criteria.includes(feedId);
}

/** The write that stores the user's list filters. */
function isStoredFilter(response: Response, feedId: string): boolean {
    return response.request().method() === 'PATCH'
      && new URL(response.url()).pathname.endsWith('/api/_info/config-me')
      && (response.request().postData() ?? '').includes(feedId);
}

/**
 * Picks a feed channel in the list's "export channel" filter on the list at `listRoute`. Core and the
 * plugin register it under the same name, which the filter panel uses as the item id.
 *
 * Choosing a filter updates the route twice, and core's listing can let a search without the filter,
 * started in between, land last and leave the filter out of the route (`listing.mixin.ts` route
 * watcher). The filter is always stored for the user, though, so the list is read from a new tab of
 * the same user at the route without a filter in it, which loads the stored filter. Returns that tab.
 */
async function filterByExportChannel(page: Page, entity: 'order' | 'customer', feed: { id: string, name: string }, listRoute: string): Promise<Page> {
    await page.locator('.sw-sidebar-navigation-item').filter({ has: page.locator('.icon--regular-filter') }).click();
    const filter = page.locator('#export-channel-filter');
    await filter.locator('.sw-select__selection').click();
    await filter.locator('input').fill(feed.name);

    const filteredSearch = page.waitForResponse(response => isFilteredSearch(response, entity, feed.id));
    const filterStored = page.waitForResponse(response => isStoredFilter(response, feed.id));
    // The result list renders in a popover outside the filter.
    await page.locator('.sw-select-result').filter({ hasText: feed.name }).click();
    for (const response of [await filteredSearch, await filterStored]) {
        expect(response.ok(), await response.text()).toBeTruthy();
    }

    const filteredList = await page.context().newPage();
    const storedFilterSearch = filteredList.waitForResponse(response => isFilteredSearch(response, entity, feed.id));
    await goToAdminRoute(filteredList, listRoute);
    await storedFilterSearch;

    return filteredList;
}

/**
 * A last name no other test shares. The list search splits terms on punctuation, so it has to be a
 * single token or it matches every other test's customers too.
 */
const uniqueLastName = () => `Feed${randomUUID().replaceAll('-', '')}`;

const rowOf = (page: Page, text: string) => page.locator('.sw-data-grid__body .sw-data-grid__row').filter({ hasText: text });

test.describe('Orders and customers attributed to a feed channel @UcpAdmin', () => {
    // Each test builds a feed channel, customers and orders through the API before it opens the Administration.
    test.slow();

    test('the order list filters by the feed channel the buyer came through', async ({ TestDataService, UcpAclUsers }) => {
        const { salesChannel: feed } = await TestDataService.createFeedSalesChannelWithExport();
        const { salesChannel: otherFeed } = await TestDataService.createFeedSalesChannelWithExport();
        const product = await TestDataService.createBasicProduct();
        const buyer = await TestDataService.createCustomer({ lastName: uniqueLastName() });
        const attributed = await TestDataService.createOrder([{ product, quantity: 1 }], buyer);
        const direct = await TestDataService.createOrder([{ product, quantity: 1 }], buyer);
        const viaOtherFeed = await TestDataService.createOrder([{ product, quantity: 1 }], buyer);
        await TestDataService.attributeOrderToFeedChannel(attributed.id, feed.id);
        await TestDataService.attributeOrderToFeedChannel(viaOtherFeed.id, otherFeed.id);

        const page = await UcpAclUsers.asFreshAdmin();
        const listRoute = `/sw/order/index?term=${buyer.lastName}`;
        await goToAdminRoute(page, listRoute);
        for (const order of [attributed, direct, viaOtherFeed]) {
            await expect(rowOf(page, order.orderNumber)).toBeVisible();
        }

        const filteredList = await filterByExportChannel(page, 'order', feed, listRoute);

        await expect(rowOf(filteredList, attributed.orderNumber)).toBeVisible();
        await expect(rowOf(filteredList, direct.orderNumber)).toHaveCount(0);
        await expect(rowOf(filteredList, viaOtherFeed.orderNumber)).toHaveCount(0);
    });

    test('the customer list filters by the feed channel the customer came through', async ({ TestDataService, UcpAclUsers }) => {
        const { salesChannel: feed } = await TestDataService.createFeedSalesChannelWithExport();
        const { salesChannel: otherFeed } = await TestDataService.createFeedSalesChannelWithExport();
        const lastName = uniqueLastName();
        const referred = await TestDataService.createCustomer({ lastName });
        const direct = await TestDataService.createCustomer({ lastName });
        const referredElsewhere = await TestDataService.createCustomer({ lastName });
        await TestDataService.attributeCustomerToFeedChannel(referred.id, feed.id);
        await TestDataService.attributeCustomerToFeedChannel(referredElsewhere.id, otherFeed.id);

        const page = await UcpAclUsers.asFreshAdmin();
        const listRoute = `/sw/customer/index?term=${lastName}`;
        await goToAdminRoute(page, listRoute);
        for (const customer of [referred, direct, referredElsewhere]) {
            await expect(rowOf(page, customer.email)).toBeVisible();
        }

        const filteredList = await filterByExportChannel(page, 'customer', feed, listRoute);

        await expect(rowOf(filteredList, referred.email)).toBeVisible();
        await expect(rowOf(filteredList, direct.email)).toHaveCount(0);
        await expect(rowOf(filteredList, referredElsewhere.email)).toHaveCount(0);
    });

    test('the feed channel\'s insights count only the orders and customers attributed to it', async ({ TestDataService, AdminPage }) => {
        const { salesChannel: feed } = await TestDataService.createFeedSalesChannelWithExport();
        const { salesChannel: otherFeed } = await TestDataService.createFeedSalesChannelWithExport();
        const product = await TestDataService.createBasicProduct();
        const referred = [await TestDataService.createCustomer(), await TestDataService.createCustomer()];
        const referredElsewhere = await TestDataService.createCustomer();

        for (const customer of referred) {
            const order = await TestDataService.createOrder([{ product, quantity: 1 }], customer);
            await TestDataService.attributeOrderToFeedChannel(order.id, feed.id);
            await TestDataService.attributeCustomerToFeedChannel(customer.id, feed.id);
        }
        // A referred customer's later direct order, and a sale through another feed, must not count.
        await TestDataService.createOrder([{ product, quantity: 1 }], referred[0]);
        const otherFeedOrder = await TestDataService.createOrder([{ product, quantity: 1 }], referredElsewhere);
        await TestDataService.attributeOrderToFeedChannel(otherFeedOrder.id, otherFeed.id);
        await TestDataService.attributeCustomerToFeedChannel(referredElsewhere.id, otherFeed.id);

        await goToAdminRoute(AdminPage, `/sw/sales/channel/detail/${feed.id}/base`);
        // From 6.7.10 core serves the insights under its own route and markup.
        await AdminPage.locator(`a[href$="/${feed.id}/product-export-insights"], a[href$="/${feed.id}/agentic-commerce-statistics"]`).click();

        const total = (card: 'orders' | 'customers') => AdminPage.getByTestId(`ac-insights__${card}-total`)
            .or(AdminPage.locator(`.sw-sales-channel-detail-product-export-insights__${card} .sw-sales-channel-detail-product-export-insights__summary`));
        await expect(total('orders')).toHaveText(/\b2$/);
        await expect(total('customers')).toHaveText(/\b2$/);
    });
});
