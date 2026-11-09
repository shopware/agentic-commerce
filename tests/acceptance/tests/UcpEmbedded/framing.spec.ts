import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { openInEmbeddingHost } from '@services/embeddedHost';
import { CARTS_PATH, CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';

const TRUSTED_HOST_ORIGIN = 'http://trusted-host.example';
const FOREIGN_HOST_ORIGIN = 'http://foreign-host.example';
const FRAMING_ONLY_HOST_ORIGIN = 'http://framing-only-host.example';
// The page answers only its own `ucp.embedded` messages, not the Embedded Protocol's JSON-RPC.
const BRIDGE_PING = { channel: 'ucp.embedded', type: 'ucp.embedded.ping' };

type EmbeddedSurface = 'cart' | 'checkout';

const CREATION_PATH_BY_SURFACE: Record<EmbeddedSurface, string> = { cart: CARTS_PATH, checkout: CHECKOUT_SESSIONS_PATH };

interface EmbeddedSession {
    url: string
    id: string
    productName: string
}

async function createEmbeddedSession(
    TestDataService: FixtureTypes['TestDataService'],
    UcpAgent: FixtureTypes['UcpAgent'],
    surface: EmbeddedSurface,
    configOverrides: { continueUrlTemplate?: string } = {},
): Promise<EmbeddedSession> {
    const embeddingChannel = await TestDataService.createStorefrontSalesChannel();
    await TestDataService.activateUcp(embeddingChannel.salesChannel.id, {
        enabledTransports: ['rest', 'embedded'],
        embeddedAllowedOrigins: [TRUSTED_HOST_ORIGIN],
        embeddedFrameAncestors: [TRUSTED_HOST_ORIGIN, FRAMING_ONLY_HOST_ORIGIN],
        ...configOverrides,
    });
    const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: embeddingChannel.salesChannel.id, visibility: 30 }] });
    const embeddedSessionResponse = await UcpAgent.at(embeddingChannel.url).rest.send('POST', CREATION_PATH_BY_SURFACE[surface], { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] } });
    expect(embeddedSessionResponse.ok(), await embeddedSessionResponse.text()).toBeTruthy();
    const { id } = (await embeddedSessionResponse.json()) as { id: string };

    return { url: `${embeddingChannel.url}ucp/embedded/${surface}/${id}`, id, productName: product.name };
}

test.describe('Embedded checkout framing @UcpEmbedded', () => {
    test('a trusted host frames the embedded checkout and a foreign host cannot', async ({ TestDataService, UcpAgent, browser }) => {
        const { url: checkoutUrl } = await createEmbeddedSession(TestDataService, UcpAgent, 'checkout');

        const trustedHost = await openInEmbeddingHost(browser, TRUSTED_HOST_ORIGIN, checkoutUrl);
        await expect(trustedHost.page.frameLocator('iframe').getByRole('heading', { name: 'Checkout session' })).toBeVisible();
        expect(trustedHost.frameAncestorRefusals).toEqual([]);

        const foreignHost = await openInEmbeddingHost(browser, FOREIGN_HOST_ORIGIN, checkoutUrl);
        await expect.poll(() => foreignHost.frameAncestorRefusals.length, 'the browser refuses to frame the page').toBeGreaterThan(0);
    });

    test('a host that may frame the page but is no allowed origin receives no message, also after it posts one', async ({ TestDataService, UcpAgent, browser }) => {
        const { url: checkoutUrl, id: checkoutId } = await createEmbeddedSession(TestDataService, UcpAgent, 'checkout');

        const framingOnlyHost = await openInEmbeddingHost(browser, FRAMING_ONLY_HOST_ORIGIN, checkoutUrl);
        await expect(framingOnlyHost.page.frameLocator('iframe').getByRole('heading', { name: 'Checkout session' })).toBeVisible();
        await framingOnlyHost.postFromHost(BRIDGE_PING);

        const trustedHost = await openInEmbeddingHost(browser, TRUSTED_HOST_ORIGIN, checkoutUrl);
        await expect.poll(async () => JSON.stringify(await trustedHost.receivedMessages()), 'the messages the embedded checkout posted to the trusted host').toContain(checkoutId);

        expect(await framingOnlyHost.receivedMessages()).toEqual([]);
    });

    test('a sibling frame on the trusted origin cannot make the embedded checkout answer the host', async ({ TestDataService, UcpAgent, browser }) => {
        const { url: checkoutUrl } = await createEmbeddedSession(TestDataService, UcpAgent, 'checkout');
        const trustedHost = await openInEmbeddingHost(browser, TRUSTED_HOST_ORIGIN, checkoutUrl, { withSiblingFrame: true });
        await expect(trustedHost.page.frameLocator('iframe[title="Embedded checkout"]').getByRole('heading', { name: 'Checkout session' })).toBeVisible();

        await trustedHost.postFromSiblingFrame(BRIDGE_PING);
        await trustedHost.postFromHost(BRIDGE_PING);

        const countReceivedPongs = async () => (await trustedHost.receivedMessages()).filter(message => (message as { type?: string }).type === 'ucp.embedded.pong').length;
        await expect.poll(countReceivedPongs, 'the pong to the host\'s own ping').toBeGreaterThan(0);
        expect(await countReceivedPongs(), 'the sibling\'s ping, sent first, got no pong').toBe(1);
    });

    test('a trusted host that frames the page without a referrer receives no message until its first ping', async ({ TestDataService, UcpAgent, browser }) => {
        const { url: checkoutUrl } = await createEmbeddedSession(TestDataService, UcpAgent, 'checkout');
        const trustedHost = await openInEmbeddingHost(browser, TRUSTED_HOST_ORIGIN, checkoutUrl, { referrerPolicy: 'no-referrer' });
        await expect(trustedHost.page.frameLocator('iframe').getByRole('heading', { name: 'Checkout session' })).toBeVisible();
        expect(await trustedHost.receivedMessages(), 'without a referrer the page does not know its parent yet').toEqual([]);

        await trustedHost.postFromHost(BRIDGE_PING);

        const receivedTypes = async () => (await trustedHost.receivedMessages()).map(message => (message as { type?: string }).type);
        await expect.poll(receivedTypes, 'the messages after the host\'s ping').toEqual(['ucp.embedded.ready', 'ucp.embedded.state', 'ucp.embedded.pong']);
    });

    test('a refresh from the trusted host makes the embedded checkout send its state again', async ({ TestDataService, UcpAgent, browser }) => {
        const { url: checkoutUrl, id: checkoutId } = await createEmbeddedSession(TestDataService, UcpAgent, 'checkout');
        const trustedHost = await openInEmbeddingHost(browser, TRUSTED_HOST_ORIGIN, checkoutUrl);
        const receivedStates = async () => (await trustedHost.receivedMessages()).filter(message => (message as { type?: string }).type === 'ucp.embedded.state') as { id?: string }[];
        await expect.poll(async () => (await receivedStates()).length, 'the state sent on load').toBe(1);

        await trustedHost.postFromHost({ channel: 'ucp.embedded', type: 'ucp.embedded.refresh' });

        await expect.poll(async () => (await receivedStates()).map(state => state.id), 'the state sent again after the refresh').toEqual([checkoutId, checkoutId]);
    });

    test('the embedded checkout links to the configured continue URL', async ({ TestDataService, UcpAgent, page }) => {
        const { url: checkoutUrl, id: checkoutId } = await createEmbeddedSession(TestDataService, UcpAgent, 'checkout', { continueUrlTemplate: 'https://shop.example/continue/{checkoutId}' });

        await page.goto(checkoutUrl);

        await expect(page.getByRole('link', { name: 'Continue checkout' })).toHaveAttribute('href', `https://shop.example/continue/${checkoutId}`);
    });

    test('the embedded cart shows the cart with its product', async ({ TestDataService, UcpAgent, page }) => {
        const { url: cartUrl, productName } = await createEmbeddedSession(TestDataService, UcpAgent, 'cart');

        await page.goto(cartUrl);

        const embeddedCart = page.getByRole('region', { name: 'UCP embedded cart' });
        await expect(embeddedCart.getByRole('heading', { name: 'Cart' })).toBeVisible();
        await expect(embeddedCart.getByRole('cell', { name: productName })).toBeVisible();
    });

    for (const surface of ['cart', 'checkout'] as const) {
        test(`a load of the embedded ${surface} without Origin is served with the host in frame-ancestors and kept private, a foreign Origin is refused`, async ({ TestDataService, UcpAgent, request }) => {
            const { url: embeddedUrl } = await createEmbeddedSession(TestDataService, UcpAgent, surface);

            const loadWithoutOrigin = await request.get(embeddedUrl);
            expect(loadWithoutOrigin.status(), await loadWithoutOrigin.text()).toBe(200);
            expect(loadWithoutOrigin.headers()).toEqual(expect.objectContaining({
                'content-security-policy': `frame-ancestors ${TRUSTED_HOST_ORIGIN} ${FRAMING_ONLY_HOST_ORIGIN}`,
                'cache-control': 'no-store, private',
                'referrer-policy': 'no-referrer',
                'x-robots-tag': 'noindex, nofollow',
            }));
            expect(loadWithoutOrigin.headers()['x-frame-options']).toBeUndefined();

            const foreignOriginLoad = await request.get(embeddedUrl, { headers: { Origin: FOREIGN_HOST_ORIGIN } });
            expect(foreignOriginLoad.status(), await foreignOriginLoad.text()).toBe(403);
        });

        test(`a preflight to the embedded ${surface} from the trusted host is granted, a preflight from a foreign host is refused`, async ({ TestDataService, UcpAgent, request }) => {
            const { url: embeddedUrl } = await createEmbeddedSession(TestDataService, UcpAgent, surface);

            const trustedPreflight = await request.fetch(embeddedUrl, { method: 'OPTIONS', headers: { Origin: TRUSTED_HOST_ORIGIN, 'Access-Control-Request-Method': 'GET' } });
            expect(trustedPreflight.status(), await trustedPreflight.text()).toBe(204);
            expect(trustedPreflight.headers()['access-control-allow-origin']).toBe(TRUSTED_HOST_ORIGIN);
            expect(trustedPreflight.headers()['access-control-allow-methods']).toContain('GET');
            expect(trustedPreflight.headers()['access-control-allow-headers']).toContain('Content-Type');
            expect(trustedPreflight.headers()['content-security-policy']).toBe(`frame-ancestors ${TRUSTED_HOST_ORIGIN} ${FRAMING_ONLY_HOST_ORIGIN}`);

            const foreignPreflight = await request.fetch(embeddedUrl, { method: 'OPTIONS', headers: { Origin: FOREIGN_HOST_ORIGIN, 'Access-Control-Request-Method': 'GET' } });
            expect(foreignPreflight.status(), await foreignPreflight.text()).toBe(403);
        });
    }
});
