export const CARTS_PATH = '/ucp/v1/carts';
export const CHECKOUT_SESSIONS_PATH = '/ucp/v1/checkout-sessions';

export interface ShoppingRoute {
    method: string
    path: string
    body?: unknown
}

const unknownId = crypto.randomUUID().replaceAll('-', '');

export const shoppingRoutes: ShoppingRoute[] = [
    { method: 'POST', path: '/ucp/v1/catalog/search', body: { query: 'product' } },
    { method: 'POST', path: '/ucp/v1/catalog/lookup', body: { ids: [unknownId] } },
    { method: 'POST', path: '/ucp/v1/catalog/product', body: { id: unknownId } },
    { method: 'POST', path: CARTS_PATH, body: { line_items: [] } },
    { method: 'GET', path: `${CARTS_PATH}/${unknownId}` },
    { method: 'PUT', path: `${CARTS_PATH}/${unknownId}`, body: { line_items: [] } },
    { method: 'POST', path: `${CARTS_PATH}/${unknownId}/cancel` },
    { method: 'POST', path: CHECKOUT_SESSIONS_PATH, body: { line_items: [] } },
    { method: 'GET', path: `${CHECKOUT_SESSIONS_PATH}/${unknownId}` },
    { method: 'PUT', path: `${CHECKOUT_SESSIONS_PATH}/${unknownId}`, body: { line_items: [] } },
    { method: 'POST', path: `${CHECKOUT_SESSIONS_PATH}/${unknownId}/complete` },
    { method: 'POST', path: `${CHECKOUT_SESSIONS_PATH}/${unknownId}/cancel` },
    { method: 'GET', path: `/ucp/v1/orders/${unknownId}` },
];
