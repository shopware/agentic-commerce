import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import type { APIRequestContext } from '@playwright/test';
import { changeOrderState, checkWebhookSignature, clearCapturedWebhook, placeOrderSendingWebhooksToCapture, readCapturedWebhook } from '@services/webhookCapture';
import type { CapturedWebhook, WebhookCapture } from '@services/webhookCapture';

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

test.describe('Order webhook @UcpSerial', () => {
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
