import { expect, test } from '@fixtures/AcceptanceTest';
import { fetchDiscoveryDocument } from '@services/discovery';

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
});
