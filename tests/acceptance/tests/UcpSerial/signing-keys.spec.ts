import { expect, test } from '@fixtures/AcceptanceTest';
import { changeOrderState, checkWebhookSignature, clearCapturedWebhook, placeOrderSendingWebhooksToCapture, readCapturedWebhook } from '@services/webhookCapture';

function sortedByKid<T extends { kid?: string }>(keys: T[]): T[] {
    return [...keys].sort((left, right) => (left.kid ?? '').localeCompare(right.kid ?? ''));
}

// The profile is read through the Administration's preview, which builds it like /.well-known/ucp does.
// A path-prefixed channel serves no /.well-known/ucp, and a channel on its own host needs a browser,
// which is not available where bin/console is reachable.
test.describe('Signing keys @UcpSerial @UcpConsole', () => {
    test('show-public prints the keys the profile publishes', async ({ TestDataService, UcpConsole }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const profileKeys = await TestDataService.getProfileSigningKeys(activatedChannel.salesChannel.id);
        const shownPublicKeys = UcpConsole.showPublicSigningKeys(activatedChannel.salesChannel.id) as { kid: string }[];

        expect(profileKeys.length).toBeGreaterThan(0);
        expect(sortedByKid(shownPublicKeys)).toEqual(sortedByKid(profileKeys));
    });

    test('after a rotation the profile publishes only the new key, which signs the next webhook', async ({ TestDataService, UcpAgent, UcpConsole, UcpSigner, request }) => {
        const { capture, salesChannelId, placedOrder } = await placeOrderSendingWebhooksToCapture(TestDataService, UcpAgent, request);
        const retiredKids = UcpConsole.listSigningKeys(salesChannelId).map(key => key.kid);
        expect(retiredKids.length, 'placing the order left the channel without a signing key').toBeGreaterThan(0);
        const rotatedKid = `rotated-${crypto.randomUUID()}`;

        UcpConsole.generateSigningKey(salesChannelId, rotatedKid);
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
    });
});
