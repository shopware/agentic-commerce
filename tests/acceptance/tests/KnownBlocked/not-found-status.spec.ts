import { expect, test } from '@fixtures/AcceptanceTest';
import { CARTS_PATH } from '@services/shoppingRoutes';
import { messageCodes } from '@services/ucpMessages';

test.describe('Not found status @UcpKnownBlocked @UcpBlockedByD26', () => {
    test('an unknown cart id is answered with 200 and not_found', async ({ TestDataService, UcpAgent }) => {
        const ucpChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(ucpChannel.salesChannel.id);

        const notFoundResponse = await UcpAgent.at(ucpChannel.url).rest.send('GET', `${CARTS_PATH}/${crypto.randomUUID().replaceAll('-', '')}`);

        expect(notFoundResponse.status(), await notFoundResponse.text()).toBe(200);
        expect(await messageCodes(notFoundResponse)).toContain('not_found');
    });
});
