import { expect, test } from '@playwright/test';
import {
    createAdminApiContext,
    firstSalesChannel,
    laneConfig,
    readPublicProfile,
    withRestoredUcpConfig,
} from '../fixtures/shopware.js';
import { ucpProtocolConfig } from '../fixtures/ucp-protocols.js';

const AP2_DESCRIPTOR = 'dev.ucp.shopping.ap2_mandate';

function base64Url(value) {
    return Buffer.from(value).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

// Matches the deterministic fixture verifier registered in smoke/e2e lanes
// (SWAG_AGENTIC_COMMERCE_TEST_AP2): `fixture.<base64url(json claims)>.fixture`.
// Three dot-separated segments, so the SDK's SD-JWT payload pattern accepts it.
function fixtureCheckoutMandate(claims) {
    return `fixture.${base64Url(JSON.stringify(claims))}.fixture`;
}

// UCP money amounts are integer minor units on the wire.
function totalAmountMinorUnits(checkout) {
    const total = (checkout.totals || []).find((entry) => entry.type === 'total');
    expect(total?.amount, 'checkout must expose a total for AP2 term comparison').toEqual(expect.any(Number));

    return total.amount;
}

function paymentInstrument(token) {
    return {
        id: 'ap2-e2e-instrument',
        type: 'tokenized',
        handler_id: 'test.ap2.psp',
        credential: { type: 'ap2_payment_mandate', token },
    };
}

// A per-run profile URI busts the platform-profile and negotiation-session
// caches, so negotiation sees the AP2 capability enabled by this test instead
// of a profile cached by earlier suites.
function createUcpRest(api, baseUrl) {
    const profileUri = `${new URL('/.well-known/ucp', baseUrl)}?ap2E2e=${Date.now()}`;
    const headers = () => ({
        'content-type': 'application/json',
        'idempotency-key': `playwright-ap2-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        'ucp-agent': `platform; profile="${profileUri}"`,
    });

    return {
        post: (path, payload) => api.post(`${baseUrl}${path}`, { headers: headers(), data: payload, failOnStatusCode: false }),
        get: (path) => api.get(`${baseUrl}${path}`, { headers: headers(), failOnStatusCode: false }),
    };
}

async function createAp2Checkout(rest) {
    const searchResponse = await rest.post('/ucp/v1/catalog/search', { query: 'music', limit: 1 });
    await expect(searchResponse, await searchResponse.text()).toBeOK();
    const product = (await searchResponse.json()).products?.[0];
    expect(product, 'catalog.search must return the seeded smoke product').toEqual(expect.objectContaining({ id: expect.any(String) }));

    const createResponse = await rest.post('/ucp/v1/checkout-sessions', {
        line_items: [{ item: { id: product.id }, quantity: 1 }],
        buyer: {
            email: `playwright-ap2-${Date.now()}@example.test`,
            first_name: 'Playwright',
            last_name: 'Ap2',
        },
        fulfillment: {
            type: 'shipping',
            extra: { shipping_address: { street: 'Smoke Street 1', zipcode: '12345', city: 'Berlin', country_code: 'DE' } },
        },
    });
    await expect(createResponse, await createResponse.text()).toBeOK();
    const checkout = await createResponse.json();
    expect(checkout.status).toBe('ready_for_complete');

    return checkout;
}

async function withAp2Enabled(api, callback) {
    const config = laneConfig();
    const adminApi = await createAdminApiContext(config);
    const { salesChannel, payload } = await firstSalesChannel(adminApi);

    try {
        await withRestoredUcpConfig(adminApi, salesChannel.id, async (originalConfig) => {
            const protocolConfig = ucpProtocolConfig(originalConfig, payload.meta, config.baseUrl);
            const saveResponse = await adminApi.put(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`, {
                data: {
                    ...protocolConfig,
                    enabledCapabilities: [...protocolConfig.enabledCapabilities, 'ap2_mandate'],
                },
            });
            await expect(saveResponse, await saveResponse.text()).toBeOK();

            const profile = await readPublicProfile(api);
            const profileRoot = profile.ucp || profile;
            const ap2Descriptors = profileRoot.capabilities?.[AP2_DESCRIPTOR];

            // Lanes without the AP2 fixture services (SWAG_AGENTIC_COMMERCE_TEST_AP2)
            // must NOT advertise AP2 even with the capability enabled in config.
            test.skip(!ap2Descriptors, 'AP2 fixture verifier not registered in this lane (SWAG_AGENTIC_COMMERCE_TEST_AP2).');

            await callback({ config, ap2Descriptors, rest: createUcpRest(api, config.baseUrl) });
        });
    } finally {
        await adminApi.dispose();
    }
}

test.describe('UCP AP2 checkout mandates', () => {
    test('enforces AP2 mandates end to end when fixtures are available', async ({ request: api }) => {
        await withAp2Enabled(api, async ({ ap2Descriptors, rest }) => {
            expect(ap2Descriptors[0].extends).toContain('dev.ucp.shopping.checkout');

            const checkout = await createAp2Checkout(rest);

            // AP2 was negotiated, so the checkout is security-locked and its
            // responses carry the merchant authorization signature.
            expect(checkout.ap2?.merchant_authorization).toEqual(expect.any(String));

            // Completion without a checkout mandate must be rejected.
            const missingMandateResponse = await rest.post(`/ucp/v1/checkout-sessions/${checkout.id}/complete`, { payment: {} });
            expect(missingMandateResponse.status()).toBe(422);
            expect((await missingMandateResponse.json()).messages[0].code).toBe('mandate_required');

            // A mandate over different terms must be rejected as scope mismatch.
            const mismatchResponse = await rest.post(`/ucp/v1/checkout-sessions/${checkout.id}/complete`, {
                payment: { instruments: [paymentInstrument('fixture_payment_mandate')] },
                ap2: {
                    checkout_mandate: fixtureCheckoutMandate({
                        checkout_id: checkout.id,
                        currency: checkout.currency,
                        total: { amount: totalAmountMinorUnits(checkout) + 1, currency: checkout.currency },
                    }),
                },
            });
            expect(mismatchResponse.status()).toBe(422);
            expect((await mismatchResponse.json()).messages[0].code).toBe('mandate_scope_mismatch');

            // A mandate covering the current terms plus an authorized fixture
            // payment completes the checkout.
            const completeResponse = await rest.post(`/ucp/v1/checkout-sessions/${checkout.id}/complete`, {
                payment: { instruments: [paymentInstrument('fixture_payment_mandate')] },
                ap2: {
                    checkout_mandate: fixtureCheckoutMandate({
                        checkout_id: checkout.id,
                        currency: checkout.currency,
                        total: { amount: totalAmountMinorUnits(checkout), currency: checkout.currency },
                    }),
                },
            });
            await expect(completeResponse, await completeResponse.text()).toBeOK();
            const completed = await completeResponse.json();
            expect(completed.status).toBe('completed');
            expect(completed.order?.id).toEqual(expect.any(String));
            expect(completed.ap2?.merchant_authorization).toEqual(expect.any(String));
        });
    });

    test('declines checkout completion when the fixture payment mandate is invalid', async ({ request: api }) => {
        await withAp2Enabled(api, async ({ rest }) => {
            const checkout = await createAp2Checkout(rest);

            const declinedResponse = await rest.post(`/ucp/v1/checkout-sessions/${checkout.id}/complete`, {
                payment: { instruments: [paymentInstrument('not-the-fixture-mandate')] },
                ap2: {
                    checkout_mandate: fixtureCheckoutMandate({
                        checkout_id: checkout.id,
                        currency: checkout.currency,
                        total: { amount: totalAmountMinorUnits(checkout), currency: checkout.currency },
                    }),
                },
            });
            expect(declinedResponse.status()).toBe(422);
            expect((await declinedResponse.json()).messages[0].code).toBe('payment_declined');
        });
    });
});
