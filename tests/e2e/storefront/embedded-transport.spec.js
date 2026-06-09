import { expect, test } from '@playwright/test';
import {
    createAdminApiContext,
    firstSalesChannel,
    laneConfig,
    readPublicProfile,
    withRestoredUcpConfig,
} from '../fixtures/shopware.js';
import {
    createA2aCart,
    shoppingTransports,
    ucpProtocolConfig,
} from '../fixtures/ucp-protocols.js';

test.describe('UCP embedded storefront transport', () => {
    test('renders the embedded cart surface in Chromium and emits bridge messages', async ({ page, request: api }) => {
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

            const { product, cart } = await createA2aCart(api, a2aEndpoint);

            await page.addInitScript(() => {
                window.__ucpEmbeddedMessages = [];
                window.addEventListener('message', (event) => {
                    window.__ucpEmbeddedMessages.push(event.data);
                });
            });
            await page.setExtraHTTPHeaders({
                Origin: config.baseUrl,
            });

            const response = await page.goto(`/ucp/embedded/cart/${encodeURIComponent(cart.id)}`, { waitUntil: 'domcontentloaded' });
            expect(response?.status()).toBe(200);
            await expect(page.locator('section[aria-label="UCP embedded cart"]')).toBeVisible();
            await expect(page.getByText(product.title)).toBeVisible();

            await expect.poll(async () => page.evaluate(() => window.__ucpEmbeddedMessages || [])).toEqual(expect.arrayContaining([
                expect.objectContaining({
                    channel: 'ucp.embedded',
                    type: 'ucp.embedded.ready',
                    surface: 'cart',
                    id: cart.id,
                }),
                expect.objectContaining({
                    channel: 'ucp.embedded',
                    type: 'ucp.embedded.state',
                    surface: 'cart',
                    id: cart.id,
                }),
            ]));
        });

        await adminApi.dispose();
    });
});
