import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { readFeedTemplates } from '@services/pluginSource';
import { feedProviders } from './feedProviders';

test.describe('Feed referral tracking @UcpContent', () => {
    test('a guest arriving through a feed link is attributed to the feed channel as customer and order', async ({
        TestDataService,
        DefaultSalesChannel,
        IdProvider,
        request,
    }) => {
        const [providerName, expectations] = Object.entries(feedProviders)[0];
        const template = (await readFeedTemplates()).find(candidate => candidate.providerName === providerName);
        expect(template).toBeDefined();
        const referredProduct = await TestDataService.createProductWithImage();

        const feed = await TestDataService.createProductFeed(template!, [referredProduct.id]);
        await TestDataService.saveFeedSettings(feed.feedChannel.id, expectations.settings);
        const exportedFeed = await request.get(feed.exportUrl);
        const [feedRow] = expectations.parse(await exportedFeed.text());

        const landing = await request.get(String(feedRow[expectations.fields.link]));
        expect(landing.status()).toBe(200);
        expect(await landing.text()).toContain(referredProduct.name);

        const guestEmail = `${IdProvider.getIdPair().uuid}@test.com`;
        const guestRegistration = await request.post(`${DefaultSalesChannel.url}account/register`, {
            form: {
                email: guestEmail,
                firstName: 'Referred',
                lastName: 'Guest',
                'billingAddress[street]': 'Feed Street 1',
                'billingAddress[zipcode]': '12345',
                'billingAddress[city]': 'Feedtown',
                'billingAddress[countryId]': DefaultSalesChannel.salesChannel.countryId,
                errorRoute: 'frontend.checkout.register.page',
            },
        });
        expect(guestRegistration.ok(), await guestRegistration.text()).toBeTruthy();

        const cartAddition = await request.post(`${DefaultSalesChannel.url}checkout/line-item/add`, {
            form: {
                [`lineItems[${referredProduct.id}][id]`]: referredProduct.id,
                [`lineItems[${referredProduct.id}][referencedId]`]: referredProduct.id,
                [`lineItems[${referredProduct.id}][type]`]: 'product',
                [`lineItems[${referredProduct.id}][quantity]`]: '1',
            },
        });
        expect(cartAddition.ok(), await cartAddition.text()).toBeTruthy();

        const orderPlacement = await request.post(`${DefaultSalesChannel.url}checkout/order`, { form: { tos: 'on' } });
        expect(orderPlacement.ok(), await orderPlacement.text()).toBeTruthy();

        const guestOrders = await searchIds(TestDataService, 'order', 'orderCustomer.email', guestEmail);
        guestOrders.forEach(guestOrderId => TestDataService.addCreatedRecord('order', guestOrderId));
        const guestCustomers = await searchIds(TestDataService, 'customer', 'email', guestEmail);
        guestCustomers.forEach(guestCustomerId => TestDataService.addCreatedRecord('customer', guestCustomerId));
        expect(guestOrders).toHaveLength(1);
        expect(guestCustomers).toHaveLength(1);

        expect(await searchIds(TestDataService, 'order', 'salesChannelTracking.salesChannelId', feed.feedChannel.id)).toEqual(guestOrders);
        expect(await searchIds(TestDataService, 'customer', 'salesChannelTracking.salesChannelId', feed.feedChannel.id)).toEqual(guestCustomers);
    });
});

async function searchIds(
    TestDataService: FixtureTypes['TestDataService'],
    entity: 'order' | 'customer',
    field: string,
    value: string,
): Promise<string[]> {
    const matchingEntities = await TestDataService.AdminApiClient.post(`./search-ids/${entity}`, {
        data: { filter: [{ type: 'equals', field, value }] },
    });
    expect(matchingEntities.ok(), await matchingEntities.text()).toBeTruthy();

    return ((await matchingEntities.json()) as { data: string[] }).data;
}
