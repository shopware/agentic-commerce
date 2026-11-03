import { expect } from '@playwright/test';
import type { APIRequestContext } from '@playwright/test';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import { createCompletableCheckout, placeOrder } from './completableCheckout';
import { UcpAgentKeyring } from './UcpAgentKeyring';
import { contentDigest } from './UcpSigner';
import type { UcpSigner } from './UcpSigner';
import type { ProfileSigningKey } from './UcpTestDataService';

export const WEBHOOK_CAPTURE_PATH = '_action/swag-agentic-commerce/test/webhooks';

const CAPTURE_REQUIREMENT = 'The capture route answers only outside prod and with SWAG_AGENTIC_COMMERCE_TEST_CAPTURE=1 in the shop\'s environment.';

export interface WebhookCapture {
    targetUrl: string
    readUrl: string
}

export interface CapturedWebhook {
    headers: Record<string, string>
    body: string
}

export interface WebhookSignatureCheck {
    keyId: string | undefined
    coveredComponents: string[]
    digestMatchesBody: boolean
    verified: boolean
}

export async function clearCapturedWebhook(request: APIRequestContext, capture: WebhookCapture): Promise<void> {
    const clearResponse = await request.delete(capture.readUrl);
    expect(clearResponse.status(), `${CAPTURE_REQUIREMENT}\n${await clearResponse.text()}`).toBe(204);
}

/** The shop keeps only the last webhook it captured, whichever channel sent it, so serial specs only. */
export async function readCapturedWebhook(request: APIRequestContext, capture: WebhookCapture): Promise<CapturedWebhook | null> {
    const readResponse = await request.get(capture.readUrl);
    const readResponseText = await readResponse.text();
    if (readResponse.status() === 404 && readResponseText === '{"data":null}') {
        return null;
    }
    expect(readResponse.status(), `${CAPTURE_REQUIREMENT}\n${readResponseText}`).toBe(200);

    return (JSON.parse(readResponseText) as { data: CapturedWebhook }).data;
}

export async function placeOrderSendingWebhooksToCapture(
    TestDataService: FixtureTypes['TestDataService'],
    UcpAgent: FixtureTypes['UcpAgent'],
    request: APIRequestContext,
) {
    const { agent, salesChannelId, channelUrl, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent);
    const webhookCaptureChannel = await TestDataService.createWebhookCaptureChannel();
    await TestDataService.activateUcp(salesChannelId, { webhookUrlOverride: webhookCaptureChannel.targetUrl });
    await clearCapturedWebhook(request, webhookCaptureChannel);
    const placedOrder = await placeOrder(agent, checkoutPath);

    return { capture: webhookCaptureChannel, salesChannelId, channelUrl, placedOrder };
}

export async function changeOrderState(TestDataService: FixtureTypes['TestDataService'], orderId: string, transition: string): Promise<void> {
    const stateChangeResponse = await TestDataService.AdminApiClient.post(`./_action/order/${orderId}/state/${transition}`, { data: {} });
    expect(stateChangeResponse.ok(), await stateChangeResponse.text()).toBeTruthy();
}

export async function checkWebhookSignature(
    webhook: CapturedWebhook,
    capture: WebhookCapture,
    profileKeys: ProfileSigningKey[],
    signer: UcpSigner,
): Promise<WebhookSignatureCheck> {
    const body = Buffer.from(webhook.body, 'utf8');
    const [, label = '', signatureParams = ''] = /^([^=]+)=(\(.*)$/.exec(webhook.headers['signature-input'] ?? '') ?? [];
    const coveredComponents = [...signatureParams.slice(0, signatureParams.indexOf(')')).matchAll(/"([^"]+)"/g)].map(match => match[1]);
    const keyId = /;keyid="([^"]+)"/.exec(signatureParams)?.[1];
    const signature = new RegExp(`^${label}=:([^:]+):$`).exec(webhook.headers.signature ?? '')?.[1];
    const profileKey = profileKeys.find(key => key.kid === keyId);

    let verified = false;
    if (signature !== undefined && profileKey !== undefined && coveredComponents.length > 0) {
        const signatureBase = signer.signatureBase({ method: 'POST', url: capture.targetUrl, headers: webhook.headers, body }, coveredComponents, signatureParams);
        verified = await UcpAgentKeyring.verifyWithJwk(profileKey, Buffer.from(signature, 'base64'), Buffer.from(signatureBase, 'utf8'));
    }

    return { keyId, coveredComponents, digestMatchesBody: webhook.headers['content-digest'] === contentDigest(body), verified };
}
