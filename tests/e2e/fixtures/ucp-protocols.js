import { expect } from '@playwright/test';

export const DEFAULT_CAPABILITIES = ['catalog', 'cart', 'discount', 'checkout', 'order'];

export function shoppingTransports(profile) {
    const profileRoot = profile.ucp || profile;

    return profileRoot.services?.['dev.ucp.shopping'] || [];
}

export function expectedShoppingTransports(meta = {}) {
    const transports = ['rest', 'a2a', 'embedded'];

    if (meta.supportsStoreApiMcp === true) {
        transports.push('mcp');
    }

    return transports;
}

export function ucpProtocolConfig(originalConfig, meta, baseUrl) {
    return {
        ...originalConfig,
        active: true,
        enabledCapabilities: DEFAULT_CAPABILITIES,
        enabledTransports: expectedShoppingTransports(meta),
        // These transport functional checks use unsigned Playwright requests.
        // Strict-signature acceptance/rejection belongs in a dedicated security
        // suite with signed fixtures; here we only prove the live protocol path.
        signaturePolicy: 'log',
        embeddedAllowedOrigins: [baseUrl],
        embeddedFrameAncestors: [baseUrl],
    };
}

export async function a2aCall(api, endpoint, method, params = {}, id = Date.now()) {
    const profileUrl = new URL('/.well-known/ucp', endpoint).toString();
    const idempotencyKey = [
        'playwright-a2a',
        method.replace(/\./g, '-'),
        Date.now(),
        Math.random().toString(36).slice(2),
    ].join('-');

    const response = await api.post(endpoint, {
        headers: {
            'content-type': 'application/json',
            'idempotency-key': idempotencyKey,
            'ucp-agent': `playwright; profile="${profileUrl}"`,
        },
        data: {
            jsonrpc: '2.0',
            id,
            method,
            params,
        },
        failOnStatusCode: false,
    });

    const body = await response.json().catch(async () => ({
        raw: await response.text(),
    }));

    return { response, body };
}

export async function expectA2aResult(api, endpoint, method, params = {}, id = Date.now()) {
    const { response, body } = await a2aCall(api, endpoint, method, params, id);

    await expect(response, JSON.stringify(body)).toBeOK();
    expect(body.jsonrpc).toBe('2.0');
    expect(body.error).toBeUndefined();
    expect(body.result).toBeTruthy();

    return body.result;
}

function productPriceAmount(product) {
    return product?.price_range?.min?.amount
        ?? product?.variants?.[0]?.price?.amount
        ?? product?.price;
}

export async function createA2aCart(api, endpoint) {
    const seed = Date.now();
    const search = await expectA2aResult(api, endpoint, 'catalog.search', {
        query: 'music',
        limit: 1,
    }, `${seed}-search`);
    const product = (search.products || search.items || [])[0];

    expect(product, 'A2A catalog.search must return a product for live protocol validation').toEqual(expect.objectContaining({
        id: expect.any(String),
        title: expect.any(String),
    }));

    const price = productPriceAmount(product);
    expect(price, 'A2A catalog.search must expose a numeric product price for cart creation').toEqual(expect.any(Number));
    const cartProduct = {
        ...product,
        price,
    };

    const cart = await expectA2aResult(api, endpoint, 'cart.create', {
        line_items: [{
            item: {
                id: cartProduct.id,
            },
            quantity: 1,
        }],
    }, `${seed}-cart-create`);

    expect(cart.id).toEqual(expect.any(String));
    expect(cart.line_items).toHaveLength(1);

    return { product: cartProduct, cart };
}

export async function createA2aCheckout(api, endpoint, product) {
    const seed = Date.now();
    const checkout = await expectA2aResult(api, endpoint, 'checkout.create', {
        line_items: [{
            item: {
                id: product.id,
            },
            quantity: 1,
        }],
        buyer: {
            email: `playwright-${Date.now()}@example.test`,
            first_name: 'Playwright',
            last_name: 'Agent',
        },
    }, `${seed}-checkout-create`);

    expect(checkout.id).toEqual(expect.any(String));
    expect(checkout.line_items).toHaveLength(1);

    return checkout;
}
