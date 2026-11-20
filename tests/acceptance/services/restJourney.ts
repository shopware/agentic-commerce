import { expect, test } from '@playwright/test';
import type { APIResponse } from '@playwright/test';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { CARTS_PATH, CHECKOUT_SESSIONS_PATH } from './shoppingRoutes';

interface UcpTotal {
    type: string
    amount: number
}

export async function grandTotal(checkoutResponse: APIResponse): Promise<number | undefined> {
    const { totals } = (await checkoutResponse.json()) as { totals: UcpTotal[] };

    return totals.find(total => total.type === 'total')?.amount;
}

/** An agent finds a product, fills a cart, converts it into a checkout and addresses it until it is ready for completion. */
export async function walkToCheckoutReadyForCompletion(
    TestDataService: FixtureTypes['TestDataService'],
    UcpAgent: FixtureTypes['UcpAgent'],
) {
    const ucpChannel = await TestDataService.createStorefrontSalesChannel();
    await TestDataService.activateUcp(ucpChannel.salesChannel.id);
    const productName = `UCP journey ${crypto.randomUUID()}`;
    const product = await TestDataService.createBasicProduct({ name: productName, visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
    const productOfOtherChannel = await TestDataService.createBasicProduct({ name: productName });
    const agent = UcpAgent.at(ucpChannel.url);

    await test.step('the catalog finds the product of this channel only', async () => {
        const searchResult = await agent.rest.send('POST', '/ucp/v1/catalog/search', { body: { query: productName } });
        expect(searchResult.status(), await searchResult.text()).toBe(200);
        const foundProductIds = ((await searchResult.json()) as { products: { id: string }[] }).products.map(foundProduct => foundProduct.id);
        expect(foundProductIds).toContain(product.id);
        expect(foundProductIds).not.toContain(productOfOtherChannel.id);

        const lookupResult = await agent.rest.send('POST', '/ucp/v1/catalog/lookup', { body: { ids: [product.id] } });
        expect(((await lookupResult.json()) as { products: { id: string }[] }).products.map(foundProduct => foundProduct.id)).toEqual([product.id]);
        const productDetail = await agent.rest.send('POST', '/ucp/v1/catalog/product', { body: { id: product.id } });
        expect(((await productDetail.json()) as { product: { id: string } }).product.id).toBe(product.id);
    });

    const cartId = await test.step('the cart keeps its id across an update', async () => {
        const createdCart = await agent.rest.send('POST', CARTS_PATH, { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] } });
        expect(createdCart.ok(), await createdCart.text()).toBeTruthy();
        const { id: createdCartId } = (await createdCart.json()) as { id: string };

        const updatedCart = await agent.rest.send('PUT', `${CARTS_PATH}/${createdCartId}`, { body: { line_items: [{ item: { id: product.id }, quantity: 2 }] } });
        expect(updatedCart.status(), await updatedCart.text()).toBe(200);
        const { id: updatedCartId, line_items: updatedCartLineItems } = (await updatedCart.json()) as { id: string, line_items: { quantity: number }[] };
        expect(updatedCartId).toBe(createdCartId);
        expect(updatedCartLineItems.map(lineItem => lineItem.quantity)).toEqual([2]);

        return createdCartId;
    });

    const buyer = { email: `ucp-journey-${crypto.randomUUID()}@example.com`, first_name: 'Ada', last_name: 'Lovelace' };

    const checkoutId = await test.step('the cart becomes one checkout, however often it is converted', async () => {
        const checkoutSession = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { cart_id: cartId, line_items: [], buyer } });
        expect(checkoutSession.ok(), await checkoutSession.text()).toBeTruthy();
        const { id: convertedCheckoutId } = (await checkoutSession.json()) as { id: string };
        expect(convertedCheckoutId, 'the checkout keeps the id of the cart it was created from').toBe(cartId);

        const repeatedCheckout = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { cart_id: cartId, line_items: [], buyer } });
        expect(((await repeatedCheckout.json()) as { id: string }).id).toBe(convertedCheckoutId);

        return convertedCheckoutId;
    });

    const checkoutTotal = await test.step('the checkout becomes ready for completion with a shipping address', async () => {
        const checkoutLineItems = ((await (await agent.rest.send('GET', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`)).json()) as { line_items: { id: string, item: { id: string }, quantity: number }[] }).line_items;
        const fulfillment = { methods: [{ type: 'shipping', line_item_ids: checkoutLineItems.map(lineItem => lineItem.id), destinations: [{ street_address: '1 Test Street', address_locality: 'London', postal_code: 'SW1A 1AA', address_country: 'GB' }] }] };
        const addressedCheckout = await agent.rest.send('PUT', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`, { body: { line_items: checkoutLineItems.map(lineItem => ({ item: lineItem.item, quantity: 3 })), buyer, fulfillment } });
        expect(addressedCheckout.status(), await addressedCheckout.text()).toBe(200);
        const { status: addressedCheckoutStatus, line_items: addressedCheckoutLineItems } = (await addressedCheckout.json()) as { status: string, line_items: { quantity: number }[] };
        expect(addressedCheckoutStatus).toBe('ready_for_complete');
        expect(addressedCheckoutLineItems.map(lineItem => lineItem.quantity), 'the update sets the quantity on the checkout').toEqual([3]);

        return grandTotal(addressedCheckout);
    });

    return { agent, buyer, checkoutTotal, salesChannelId: ucpChannel.salesChannel.id, checkoutPath: `${CHECKOUT_SESSIONS_PATH}/${checkoutId}` };
}
