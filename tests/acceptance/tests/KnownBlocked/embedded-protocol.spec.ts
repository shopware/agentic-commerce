import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { openInEmbeddingHost } from '@services/embeddedHost';
import { CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';

const TRUSTED_HOST_ORIGIN = 'http://trusted-host.example';

interface CheckoutWithServices {
    continue_url?: string
    ucp: { version: string, services?: Record<string, { transport: string, config?: { delegate?: unknown } }[]> }
}

async function createEmbeddableCheckout(TestDataService: FixtureTypes['TestDataService'], UcpAgent: FixtureTypes['UcpAgent']): Promise<CheckoutWithServices> {
    const embeddingChannel = await TestDataService.createStorefrontSalesChannel();
    await TestDataService.activateUcp(embeddingChannel.salesChannel.id, {
        enabledTransports: ['rest', 'embedded'],
        embeddedAllowedOrigins: [TRUSTED_HOST_ORIGIN],
        embeddedFrameAncestors: [TRUSTED_HOST_ORIGIN],
    });
    const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: embeddingChannel.salesChannel.id, visibility: 30 }] });
    const checkoutCreation = await UcpAgent.at(embeddingChannel.url).rest.send('POST', CHECKOUT_SESSIONS_PATH, { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] } });
    expect(checkoutCreation.ok(), await checkoutCreation.text()).toBeTruthy();

    return (await checkoutCreation.json()) as CheckoutWithServices;
}

test.describe('Embedded checkout binding @UcpKnownBlocked @UcpBlockedByD36', () => {
    test('a checkout on a channel with the embedded transport carries an embedded service binding with config.delegate', async ({ TestDataService, UcpAgent }) => {
        const embeddableCheckout = await createEmbeddableCheckout(TestDataService, UcpAgent);

        const embeddedBindings = Object.values(embeddableCheckout.ucp.services ?? {}).flat().filter(service => service.transport === 'embedded');
        expect(embeddedBindings, JSON.stringify(embeddableCheckout.ucp, null, 2)).toEqual([expect.objectContaining({ config: expect.objectContaining({ delegate: expect.any(Array) }) })]);
    });
});

test.describe('Embedded checkout handshake @UcpKnownBlocked @UcpBlockedByD37', () => {
    test('the continue_url, loaded with ec_version by a trusted host, sends the ec.ready request', async ({ TestDataService, UcpAgent, browser }) => {
        const embeddableCheckout = await createEmbeddableCheckout(TestDataService, UcpAgent);
        expect(embeddableCheckout.continue_url, JSON.stringify(embeddableCheckout, null, 2)).toEqual(expect.any(String));
        const sessionUrl = new URL(embeddableCheckout.continue_url ?? '');
        sessionUrl.searchParams.set('ec_version', embeddableCheckout.ucp.version);

        const trustedHost = await openInEmbeddingHost(browser, TRUSTED_HOST_ORIGIN, sessionUrl.toString());

        await expect.poll(() => trustedHost.receivedMessages(), 'the messages the embedded checkout posted to the host')
            .toContainEqual(expect.objectContaining({ jsonrpc: '2.0', method: 'ec.ready', id: expect.anything(), params: expect.objectContaining({ delegate: expect.any(Array) }) }));
    });
});
