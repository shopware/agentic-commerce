import { expect, test } from '@fixtures/AcceptanceTest';
import { createCompletableCheckout, placeOrder } from '@services/completableCheckout';
import { changeOrderState, checkWebhookSignature, clearCapturedWebhook, placeOrderSendingWebhooksToCapture, readCapturedWebhook } from '@services/webhookCapture';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// The shop keeps only the last captured webhook, so the tests of this file must not overlap.
test.describe.configure({ mode: 'default' });

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
        const { agent, checkoutPath } = await createCompletableCheckout(TestDataService, agentPublishingWebhookUrl);
        await clearCapturedWebhook(request, capture);

        const placedOrder = await placeOrder(agent, checkoutPath);

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
