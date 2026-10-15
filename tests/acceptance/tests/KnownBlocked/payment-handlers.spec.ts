import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('Payment handlers in the UCP profile @UcpKnownBlocked @UcpBlockedByD2', () => {
    test('an activated channel advertises a payment handler an agent can complete a checkout with', async ({ TestDataService }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const profile = await TestDataService.getProfilePreview(activatedChannel.salesChannel.id);

        expect(Object.keys(profile.payment_handlers ?? {}), 'checkout.complete needs a payment instrument bound to an advertised handler').not.toHaveLength(0);
    });
});
