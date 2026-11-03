import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { openInEmbeddingHost } from '@services/embeddedHost';
import { CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';

const TRUSTED_HOST_ORIGIN = 'http://trusted-host.example';
const FOREIGN_HOST_ORIGIN = 'http://foreign-host.example';

async function embeddedCheckoutUrl(TestDataService: FixtureTypes['TestDataService'], UcpAgent: FixtureTypes['UcpAgent']): Promise<string> {
    const embeddingChannel = await TestDataService.createStorefrontSalesChannel();
    await TestDataService.activateUcp(embeddingChannel.salesChannel.id, {
        enabledTransports: ['rest', 'embedded'],
        embeddedAllowedOrigins: [TRUSTED_HOST_ORIGIN],
        embeddedFrameAncestors: [TRUSTED_HOST_ORIGIN],
    });
    const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: embeddingChannel.salesChannel.id, visibility: 30 }] });
    const checkoutCreation = await UcpAgent.at(embeddingChannel.url).rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] } });
    expect(checkoutCreation.ok(), await checkoutCreation.text()).toBeTruthy();
    const { id: checkoutId } = (await checkoutCreation.json()) as { id: string };

    return `${embeddingChannel.url}ucp/embedded/checkout/${checkoutId}`;
}

test.describe('Embedded checkout framing @UcpEmbedded', () => {
    test('a trusted host frames the embedded checkout and a foreign host cannot', async ({ TestDataService, UcpAgent, browser }) => {
        const checkoutUrl = await embeddedCheckoutUrl(TestDataService, UcpAgent);

        const trustedHost = await openInEmbeddingHost(browser, TRUSTED_HOST_ORIGIN, checkoutUrl);
        await expect(trustedHost.page.frameLocator('iframe').getByRole('heading', { name: 'Checkout session' })).toBeVisible();
        expect(trustedHost.frameAncestorRefusals).toEqual([]);

        const foreignHost = await openInEmbeddingHost(browser, FOREIGN_HOST_ORIGIN, checkoutUrl);
        await expect.poll(() => foreignHost.frameAncestorRefusals.length, 'the browser refuses to frame the page').toBeGreaterThan(0);
    });

    test('a load without Origin is served with the host in frame-ancestors, a foreign Origin is refused', async ({ TestDataService, UcpAgent, request }) => {
        const checkoutUrl = await embeddedCheckoutUrl(TestDataService, UcpAgent);

        const loadWithoutOrigin = await request.get(checkoutUrl);
        expect(loadWithoutOrigin.status(), await loadWithoutOrigin.text()).toBe(200);
        expect(loadWithoutOrigin.headers()['content-security-policy']).toBe(`frame-ancestors ${TRUSTED_HOST_ORIGIN}`);
        expect(loadWithoutOrigin.headers()['x-frame-options']).toBeUndefined();

        const foreignOriginLoad = await request.get(checkoutUrl, { headers: { Origin: FOREIGN_HOST_ORIGIN } });
        expect(foreignOriginLoad.status(), await foreignOriginLoad.text()).toBe(403);
    });
});
