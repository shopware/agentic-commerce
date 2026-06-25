import { expect, test } from '@playwright/test';
import {
    createAdminApiContext,
    firstSalesChannel,
    laneConfig,
    readPublicProfile,
    withRestoredUcpConfig,
} from '../fixtures/shopware.js';
import {
    a2aCall,
    createA2aCart,
    createA2aCheckout,
    expectA2aResult,
    shoppingTransports,
    ucpProtocolConfig,
} from '../fixtures/ucp-protocols.js';

test.describe('UCP live protocol transports', () => {
    test('serves A2A shopping operations through the same live Shopware capabilities', async ({ request: api }) => {
        const config = laneConfig();
        const adminApi = await createAdminApiContext(config);
        const { salesChannel, payload } = await firstSalesChannel(adminApi);

        await withRestoredUcpConfig(adminApi, salesChannel.id, async (originalConfig) => {
            const saveResponse = await adminApi.put(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`, {
                data: ucpProtocolConfig(originalConfig, payload.meta, config.baseUrl),
            });
            await expect(saveResponse, await saveResponse.text()).toBeOK();

            const profile = await readPublicProfile(api);
            const a2aEndpoint = shoppingTransports(profile).find((entry) => entry.transport === 'a2a')?.endpoint;
            expect(a2aEndpoint).toBe(`${config.baseUrl}/ucp/a2a`);

            const agentCardResponse = await api.get('/.well-known/agent-card.json');
            await expect(agentCardResponse, await agentCardResponse.text()).toBeOK();
            const agentCard = await agentCardResponse.json();
            expect(agentCard.url).toBe(a2aEndpoint);
            expect(agentCard.metadata.transports).toEqual(expect.arrayContaining(['a2a']));

            const { product, cart } = await createA2aCart(api, a2aEndpoint);

            const loadedProductResponse = await expectA2aResult(api, a2aEndpoint, 'catalog.product', { id: product.id }, 1003);
            const loadedProduct = loadedProductResponse.product;
            expect(loadedProduct.id).toBe(product.id);
            expect(loadedProduct.title).toBe(product.title);

            const loadedCart = await expectA2aResult(api, a2aEndpoint, 'cart.get', { id: cart.id }, 1004);
            expect(loadedCart.id).toBe(cart.id);
            expect(loadedCart.line_items).toHaveLength(1);

            const updatedCart = await expectA2aResult(api, a2aEndpoint, 'cart.update', {
                id: cart.id,
                line_items: [{
                    item: {
                        id: product.id,
                        title: product.title,
                        price: product.price,
                    },
                    quantity: 2,
                }],
            }, 1005);
            expect(updatedCart.line_items[0].quantity).toBe(2);

            const canceledCart = await expectA2aResult(api, a2aEndpoint, 'cart.cancel', { id: cart.id }, 1006);
            expect(canceledCart.id).toBe(cart.id);
            expect(canceledCart.line_items).toEqual([]);

            const invalidParams = await a2aCall(api, a2aEndpoint, 'cart.get', [], 1007);
            expect(invalidParams.response.status()).toBe(400);
            expect(invalidParams.body.error.code).toBe(-32602);
        });

        await adminApi.dispose();
    });

    test('serves embedded cart and checkout pages with origin-pinned headers', async ({ request: api }) => {
        const config = laneConfig();
        const adminApi = await createAdminApiContext(config);
        const { salesChannel, payload } = await firstSalesChannel(adminApi);

        await withRestoredUcpConfig(adminApi, salesChannel.id, async (originalConfig) => {
            const saveResponse = await adminApi.put(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`, {
                data: ucpProtocolConfig(originalConfig, payload.meta, config.baseUrl),
            });
            await expect(saveResponse, await saveResponse.text()).toBeOK();

            const profile = await readPublicProfile(api);
            const embeddedEndpoint = shoppingTransports(profile).find((entry) => entry.transport === 'embedded')?.endpoint;
            const a2aEndpoint = shoppingTransports(profile).find((entry) => entry.transport === 'a2a')?.endpoint;
            expect(embeddedEndpoint).toBe(`${config.baseUrl}/ucp/embedded`);
            expect(a2aEndpoint).toBe(`${config.baseUrl}/ucp/a2a`);

            const { product, cart } = await createA2aCart(api, a2aEndpoint);
            const checkout = await createA2aCheckout(api, a2aEndpoint, product);

            const blockedResponse = await api.get(`/ucp/embedded/cart/${encodeURIComponent(cart.id)}`, {
                headers: {
                    origin: 'https://evil.example',
                },
                failOnStatusCode: false,
            });
            expect(blockedResponse.status()).toBe(403);

            const cartResponse = await api.get(`/ucp/embedded/cart/${encodeURIComponent(cart.id)}`, {
                headers: {
                    origin: config.baseUrl,
                },
            });
            await expect(cartResponse, await cartResponse.text()).toBeOK();
            expect(cartResponse.headers()['access-control-allow-origin']).toBe(config.baseUrl);
            expect(cartResponse.headers()['content-security-policy']).toContain(`frame-ancestors ${config.baseUrl}`);
            expect(cartResponse.headers()['x-frame-options']).toBeUndefined();
            const cartHtml = await cartResponse.text();
            expect(cartHtml).toContain('UCP embedded cart');
            expect(cartHtml).toContain(product.title);
            expect(cartHtml).toContain('ucp.embedded.ready');

            const checkoutResponse = await api.get(`/ucp/embedded/checkout/${encodeURIComponent(checkout.id)}`, {
                headers: {
                    origin: config.baseUrl,
                },
            });
            await expect(checkoutResponse, await checkoutResponse.text()).toBeOK();
            const checkoutHtml = await checkoutResponse.text();
            expect(checkoutHtml).toContain('Checkout session');
            expect(checkoutHtml).toContain('Continue checkout');
            expect(checkoutHtml).toContain('ucp.embedded.ready');

            const preflightResponse = await api.fetch(`/ucp/embedded/cart/${encodeURIComponent(cart.id)}`, {
                method: 'OPTIONS',
                headers: {
                    origin: config.baseUrl,
                },
            });
            expect(preflightResponse.status()).toBe(204);
            expect(preflightResponse.headers()['access-control-allow-methods']).toContain('GET');
            expect(preflightResponse.headers()['access-control-allow-headers']).toContain('Content-Type');
        });

        await adminApi.dispose();
    });
});
