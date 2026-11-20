import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import type { APIRequestContext } from '@playwright/test';
import { createCompletableCheckout, placeOrder } from '@services/completableCheckout';
import { changeOrderState, checkWebhookSignature, clearCapturedWebhook, placeOrderSendingWebhooksToCapture, readCapturedWebhook } from '@services/webhookCapture';
import type { CapturedWebhook, WebhookCapture } from '@services/webhookCapture';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// The shop keeps only the last captured webhook, so the tests of this file must not overlap.
test.describe.configure({ mode: 'default' });

async function expectSignedOrderWebhook(
    { TestDataService, UcpSigner, request }: Pick<FixtureTypes, 'TestDataService' | 'UcpSigner'> & { request: APIRequestContext },
    capture: WebhookCapture,
    salesChannelId: string,
    orderId: string,
): Promise<CapturedWebhook> {
    const webhook = await readCapturedWebhook(request, capture);
    expect(webhook, 'the shop sent no webhook').not.toBeNull();
    expect(webhook!.body, 'the captured webhook is about this order').toContain(orderId);
    expect(webhook!.headers['webhook-id']).toBeTruthy();
    expect(webhook!.headers['webhook-timestamp']).toMatch(/^\d+$/);

    const signatureCheck = await checkWebhookSignature(webhook!, capture, await TestDataService.getProfileSigningKeys(salesChannelId), UcpSigner);
    expect(signatureCheck.digestMatchesBody, webhook!.headers['content-digest']).toBe(true);
    expect(signatureCheck.coveredComponents).toContain('content-digest');
    expect(signatureCheck.verified, `${webhook!.headers['signature-input']} with key ${signatureCheck.keyId}`).toBe(true);

    return webhook!;
}

test.describe('Order webhook signature @UcpKnownBlocked @UcpBlockedByD28', () => {
    test('the signature covers the components of REST request signing and carries no alg', async ({ TestDataService, UcpAgent, UcpSigner, request }) => {
        const { capture, salesChannelId } = await placeOrderSendingWebhooksToCapture(TestDataService, UcpAgent, request);
        const creationWebhook = await readCapturedWebhook(request, capture);
        expect(creationWebhook, 'the shop sent no webhook').not.toBeNull();

        const signatureCheck = await checkWebhookSignature(creationWebhook!, capture, await TestDataService.getProfileSigningKeys(salesChannelId), UcpSigner);

        expect(signatureCheck.coveredComponents).toEqual(expect.arrayContaining(['@method', '@authority', '@path', 'ucp-agent', 'content-digest', 'content-type']));
        expect(creationWebhook!.headers['signature-input']).not.toContain(';alg=');
        expect(signatureCheck.verified).toBe(true);
    });
});

test.describe('Order webhook id @UcpKnownBlocked @UcpBlockedByD29', () => {
    test('each order state change sends a webhook with its own UUID', async ({ TestDataService, UcpAgent, request }) => {
        const { capture, placedOrder } = await placeOrderSendingWebhooksToCapture(TestDataService, UcpAgent, request);

        const webhookIds: string[] = [];
        for (const transition of ['process', 'complete']) {
            await clearCapturedWebhook(request, capture);
            await changeOrderState(TestDataService, placedOrder.id, transition);
            const stateChangeWebhook = await readCapturedWebhook(request, capture);
            expect(stateChangeWebhook, `the shop sent no webhook for ${transition}`).not.toBeNull();
            webhookIds.push(stateChangeWebhook!.headers['webhook-id']);
        }

        expect(new Set(webhookIds).size, webhookIds.join(', ')).toBe(2);
        for (const webhookId of webhookIds) {
            expect(webhookId).toMatch(UUID);
        }
    });
});

test.describe('Order update webhook @UcpKnownBlocked @UcpBlockedByD30', () => {
    test('a state change webhook names the shop\'s profile and links the order on the channel\'s domain', async ({ TestDataService, UcpAgent, request }) => {
        const { capture, channelUrl, placedOrder } = await placeOrderSendingWebhooksToCapture(TestDataService, UcpAgent, request);
        await clearCapturedWebhook(request, capture);
        await changeOrderState(TestDataService, placedOrder.id, 'process');

        const stateChangeWebhook = await readCapturedWebhook(request, capture);
        expect(stateChangeWebhook, 'the shop sent no webhook').not.toBeNull();
        const { order } = JSON.parse(stateChangeWebhook!.body) as { order: { permalink_url: string } };

        expect(stateChangeWebhook!.headers['ucp-agent']).toMatch(/^profile="[^"]+\/\.well-known\/ucp"$/);
        expect(new URL(order.permalink_url).host, order.permalink_url).toBe(new URL(channelUrl).host);
    });
});

test.describe('Order webhook target and body @UcpKnownBlocked @UcpBlockedByD31', () => {
    test('completion sends the order to the webhook_url the agent publishes', async ({ TestDataService, UcpAgentWithProfile, request }) => {
        const capture = await TestDataService.createWebhookCaptureChannel();
        const agentPublishingWebhookUrl = await UcpAgentWithProfile({ orderWebhookUrl: capture.targetUrl });
        const { agent, salesChannelId, checkoutPath } = await createCompletableCheckout(TestDataService, agentPublishingWebhookUrl);
        await clearCapturedWebhook(request, capture);

        const placedOrder = await placeOrder(TestDataService, agent, salesChannelId, checkoutPath);

        const creationWebhook = await readCapturedWebhook(request, capture);
        expect(creationWebhook, 'no webhook reached the URL the agent published').not.toBeNull();
        expect(creationWebhook!.body).toContain(placedOrder.id);
    });

    test('the webhook body is the order', async ({ TestDataService, UcpAgent, request }) => {
        const { capture, placedOrder } = await placeOrderSendingWebhooksToCapture(TestDataService, UcpAgent, request);

        const creationWebhook = await readCapturedWebhook(request, capture);
        expect(creationWebhook, 'the shop sent no webhook').not.toBeNull();

        expect(JSON.parse(creationWebhook!.body)).toMatchObject({ id: placedOrder.id });
    });
});

test.describe('Order webhook @UcpKnownBlocked @UcpBlockedByD2', () => {
    test('a completed checkout and a later order state change each send a signed webhook', async ({ TestDataService, UcpAgent, UcpSigner, request }) => {
        const { capture, salesChannelId, placedOrder } = await placeOrderSendingWebhooksToCapture(TestDataService, UcpAgent, request);

        await test.step('completion sends the new order', async () => {
            const creationWebhook = await expectSignedOrderWebhook({ TestDataService, UcpSigner, request }, capture, salesChannelId, placedOrder.id);
            expect(creationWebhook.headers['ucp-agent']).toMatch(/^profile="[^"]+\/\.well-known\/ucp"$/);
        });

        await test.step('a state change sends the order again', async () => {
            await clearCapturedWebhook(request, capture);
            await changeOrderState(TestDataService, placedOrder.id, 'process');

            await expectSignedOrderWebhook({ TestDataService, UcpSigner, request }, capture, salesChannelId, placedOrder.id);
        });
    });
});

test.describe('Signing keys @UcpKnownBlocked @UcpBlockedByD2 @UcpConsole', () => {
    // The agent reaches only path-prefixed channels, which serve no /.well-known/ucp. The keys are read
    // through the Administration's preview, which builds the profile like /.well-known/ucp does.
    test('after a rotation the profile publishes only the new key, which signs the next webhook', async ({ TestDataService, UcpAgent, UcpConsole, UcpSigner, request }) => {
        const { capture, salesChannelId, placedOrder } = await placeOrderSendingWebhooksToCapture(TestDataService, UcpAgent, request);
        const retiredKids = UcpConsole.listSigningKeys(salesChannelId).map(key => key.kid);
        expect(retiredKids.length, 'placing the order left the channel without a signing key').toBeGreaterThan(0);
        const rotatedKid = `rotated-${crypto.randomUUID()}`;

        UcpConsole.generateSigningKey(salesChannelId, rotatedKid);
        expect(UcpConsole.listSigningKeys(salesChannelId).map(key => key.kid)).toContain(rotatedKid);
        for (const retiredKid of retiredKids) {
            UcpConsole.retireSigningKey(salesChannelId, retiredKid);
        }
        await clearCapturedWebhook(request, capture);
        await changeOrderState(TestDataService, placedOrder.id, 'process');

        const profileKeys = await TestDataService.getProfileSigningKeys(salesChannelId);
        expect(profileKeys.map(key => key.kid)).toEqual([rotatedKid]);
        expect(UcpConsole.showPublicSigningKeys(salesChannelId)).toEqual(profileKeys);

        const stateChangeWebhook = await readCapturedWebhook(request, capture);
        expect(stateChangeWebhook, 'the shop sent no webhook').not.toBeNull();
        const signatureCheck = await checkWebhookSignature(stateChangeWebhook!, capture, profileKeys, UcpSigner);
        expect(signatureCheck.keyId).toBe(rotatedKid);
        expect(signatureCheck.verified, stateChangeWebhook!.headers['signature-input']).toBe(true);

        for (const retiredKid of retiredKids) {
            UcpConsole.deleteSigningKey(salesChannelId, retiredKid);
        }
        expect(UcpConsole.listSigningKeys(salesChannelId).map(key => key.kid)).toEqual([rotatedKid]);
    });
});
