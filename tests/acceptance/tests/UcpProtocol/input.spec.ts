import { expect, test } from '@fixtures/AcceptanceTest';
import { CARTS_PATH, CHECKOUT_SESSIONS_PATH, shoppingRoutes } from '@services/shoppingRoutes';
import type { ShoppingRoute } from '@services/shoppingRoutes';
import { isRefusal, messageCodes } from '@services/ucpMessages';

const unknownId = crypto.randomUUID().replaceAll('-', '');
const lineItemsThatAreNoList = { line_items: 'one product' };

test.describe('Request input @UcpProtocol', () => {
    test('a schema violation on a route that takes a body is refused without a server error', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const agent = UcpAgent.at(ucpChannel.url);
        const lineItems = [{ item: { id: product.id }, quantity: 1 }];
        const buyer = { email: `ucp-input-${crypto.randomUUID()}@example.com`, first_name: 'Ada', last_name: 'Lovelace' };
        const fulfillment = { methods: [{ type: 'shipping', destinations: [{ street_address: '1 Test Street', address_locality: 'London', postal_code: 'SW1A 1AA', address_country: 'GB' }] }] };

        const existingCart = await agent.rest.send('POST', CARTS_PATH, { body: { line_items: lineItems } });
        expect(existingCart.ok(), await existingCart.text()).toBeTruthy();
        const { id: cartId } = (await existingCart.json()) as { id: string };
        const completableCheckout = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: lineItems, buyer, fulfillment } });
        expect(completableCheckout.ok(), await completableCheckout.text()).toBeTruthy();
        const { id: checkoutId } = (await completableCheckout.json()) as { id: string };

        const schemaViolations: ShoppingRoute[] = [
            { method: 'POST', path: CARTS_PATH, body: lineItemsThatAreNoList },
            { method: 'PUT', path: `${CARTS_PATH}/${cartId}`, body: lineItemsThatAreNoList },
            { method: 'POST', path: CHECKOUT_SESSIONS_PATH, body: lineItemsThatAreNoList },
            { method: 'PUT', path: `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`, body: lineItemsThatAreNoList },
            { method: 'POST', path: `${CHECKOUT_SESSIONS_PATH}/${checkoutId}/complete`, body: { payment: 'card' } },
        ];
        const answers: { route: string, refused: boolean, answer: string }[] = [];
        for (const schemaViolation of schemaViolations) {
            const answer = await agent.rest.send(schemaViolation.method, schemaViolation.path, { body: schemaViolation.body });
            answers.push({ route: `${schemaViolation.method} ${schemaViolation.path}`, refused: await isRefusal(answer), answer: `${answer.status()} ${await answer.text()}` });
        }

        expect(answers.filter(answer => !answer.refused).map(answer => `${answer.route}: ${answer.answer}`)).toEqual([]);
    });

    test('a body that is not JSON is refused with a client error on every route that takes a body', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const agent = UcpAgent.at(ucpChannel.url);

        const statusByRoute: Record<string, number> = {};
        for (const route of shoppingRoutes.filter(shoppingRoute => shoppingRoute.body !== undefined)) {
            statusByRoute[`${route.method} ${route.path}`] = (await agent.rest.send(route.method, route.path, { rawBody: '{"line_items": [' })).status();
        }

        const routesWithoutClientError = Object.entries(statusByRoute).filter(([, status]) => status < 400 || status >= 500);
        expect(routesWithoutClientError, JSON.stringify(statusByRoute, null, 2)).toEqual([]);
    });

    test('an unknown cart id is answered with not_found', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        const unknownCart = await UcpAgent.at(ucpChannel.url).rest.send('GET', `${CARTS_PATH}/${unknownId}`);

        expect(await messageCodes(unknownCart), await unknownCart.text()).toContain('not_found');
    });

    test('an invalid discount code on a checkout is answered with 200 and a message', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: ucpChannel.salesChannel.id, visibility: 30 }] });
        const lineItems = [{ item: { id: product.id }, quantity: 1 }];
        const agent = UcpAgent.at(ucpChannel.url);

        const checkoutBeforeDiscount = await agent.rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: lineItems } });
        expect(checkoutBeforeDiscount.ok(), await checkoutBeforeDiscount.text()).toBeTruthy();
        const { id: checkoutId } = (await checkoutBeforeDiscount.json()) as { id: string };

        const unknownCodeRefusal = await agent.rest.send('PUT', `${CHECKOUT_SESSIONS_PATH}/${checkoutId}`, { body: { line_items: lineItems, discounts: { codes: ['NO-SUCH-CODE'] } } });

        expect(unknownCodeRefusal.status(), await unknownCodeRefusal.text()).toBe(200);
        expect(await messageCodes(unknownCodeRefusal)).not.toHaveLength(0);
    });
});
