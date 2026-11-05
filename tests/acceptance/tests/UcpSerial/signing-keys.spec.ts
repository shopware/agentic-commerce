import { expect, test } from '@fixtures/AcceptanceTest';
import { fetchDiscoveryDocument } from '@services/discovery';
import { changeOrderState, checkWebhookSignature, clearCapturedWebhook, placeOrderSendingWebhooksToCapture, readCapturedWebhook } from '@services/webhookCapture';

function sortedByKid<T extends { kid?: string }>(keys: T[]): T[] {
    return [...keys].sort((left, right) => (left.kid ?? '').localeCompare(right.kid ?? ''));
}

test.describe('Signing keys @UcpSerial @UcpConsole', () => {
    test('show-public prints the keys the profile publishes', async ({ TestDataService, UcpConsole, browser }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const { keys: publishedKeys } = await fetchDiscoveryDocument(browser, activatedChannel.url);
        const shownPublicKeys = UcpConsole.showPublicSigningKeys(activatedChannel.salesChannel.id) as { kid: string }[];

        expect(publishedKeys.length).toBeGreaterThan(0);
        expect(sortedByKid(shownPublicKeys)).toEqual(sortedByKid(publishedKeys));
    });

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
