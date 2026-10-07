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

        const referredOrders = await searchByReferringChannel(TestDataService, 'order', feed.feedChannel.id);
        for (const referredOrder of referredOrders) {
            TestDataService.addCreatedRecord('order', referredOrder.id);
        }
        const referredCustomers = await searchByReferringChannel(TestDataService, 'customer', feed.feedChannel.id);
        for (const referredCustomer of referredCustomers) {
            TestDataService.addCreatedRecord('customer', referredCustomer.id);
        }

        expect(referredOrders).toHaveLength(1);
        expect(referredCustomers).toHaveLength(1);
    });
});

async function searchByReferringChannel(
    TestDataService: FixtureTypes['TestDataService'],
    entity: 'order' | 'customer',
    feedChannelId: string,
): Promise<{ id: string }[]> {
    const referredEntitiesLookup = await TestDataService.AdminApiClient.post(`./search/${entity}`, {
        data: { filter: [{ type: 'equals', field: 'salesChannelTracking.salesChannelId', value: feedChannelId }] },
    });
    expect(referredEntitiesLookup.ok(), await referredEntitiesLookup.text()).toBeTruthy();

    return ((await referredEntitiesLookup.json()) as { data: { id: string }[] }).data;
}
