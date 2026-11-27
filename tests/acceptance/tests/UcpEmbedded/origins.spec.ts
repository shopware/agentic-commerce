import { expect, test } from '@fixtures/AcceptanceTest';
import { CARTS_PATH, CHECKOUT_SESSIONS_PATH } from '@services/shoppingRoutes';

const ALLOWED_HOST_ORIGIN = 'http://allowed-host.example';
const FOREIGN_HOST_ORIGIN = 'http://foreign-host.example';

test.describe('Embedded allowed origin reading the page @UcpEmbedded', () => {
    for (const [surface, creationPath] of [['cart', CARTS_PATH], ['checkout', CHECKOUT_SESSIONS_PATH]] as const) {
        for (const platformPolicy of ['listed', 'all'] as const) {
            test(`a GET to the embedded ${surface} is served to an allowed origin and refused to a foreign one, with ${platformPolicy} platforms`, async ({ TestDataService, UcpAgent, request }) => {
                const embeddingChannel = await TestDataService.createStorefrontSalesChannel();
                await TestDataService.activateUcp(embeddingChannel.salesChannel.id, {
                    enabledTransports: ['rest', 'embedded'],
                    embeddedAllowedOrigins: [ALLOWED_HOST_ORIGIN],
                    embeddedFrameAncestors: [ALLOWED_HOST_ORIGIN],
                    ...(platformPolicy === 'all' ? { platformPolicy, agentAllowlist: [] } : {}),
                });
                const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: embeddingChannel.salesChannel.id, visibility: 30 }] });
                const createdEmbeddedSession = await UcpAgent.at(embeddingChannel.url).rest.send('POST', creationPath, { body: { line_items: [{ item: { id: product.id }, quantity: 1 }] } });
                expect(createdEmbeddedSession.ok(), await createdEmbeddedSession.text()).toBeTruthy();
                const { id } = (await createdEmbeddedSession.json()) as { id: string };
                const embeddedUrl = `${embeddingChannel.url}ucp/embedded/${surface}/${id}`;

                const allowedOriginResponse = await request.get(embeddedUrl, { headers: { Origin: ALLOWED_HOST_ORIGIN } });

                expect(allowedOriginResponse.status(), await allowedOriginResponse.text()).toBe(200);
                expect(allowedOriginResponse.headers()['access-control-allow-origin']).toBe(ALLOWED_HOST_ORIGIN);
                expect(allowedOriginResponse.headers()['content-security-policy']).toBe(`frame-ancestors ${ALLOWED_HOST_ORIGIN}`);

                const foreignOriginResponse = await request.get(embeddedUrl, { headers: { Origin: FOREIGN_HOST_ORIGIN } });

                expect(foreignOriginResponse.status()).toBe(403);
                expect(foreignOriginResponse.headers()['access-control-allow-origin']).not.toBe(FOREIGN_HOST_ORIGIN);
            });
        }
    }
});
