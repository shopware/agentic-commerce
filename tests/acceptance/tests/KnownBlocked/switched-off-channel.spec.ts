import { expect, test } from '@fixtures/AcceptanceTest';
import { fetchRawDiscoveryResponse } from '@services/discovery';
import { shoppingRoutes } from '@services/shoppingRoutes';

test.describe('A channel with UCP switched off @UcpKnownBlocked @UcpBlockedByD17', () => {
    test('a channel with UCP switched off answers 404 on every shopping route', async ({ TestDataService, UcpAgent }) => {
        const switchedOffChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(switchedOffChannel.salesChannel.id);
        const switchOffResponse = await TestDataService.saveUcpConfig(switchedOffChannel.salesChannel.id, { active: false });
        expect(switchOffResponse.ok(), await switchOffResponse.text()).toBeTruthy();

        const refusalStatusByRoute: Record<string, number> = {};
        for (const route of shoppingRoutes) {
            const refusal = await UcpAgent.at(switchedOffChannel.url).rest.send(route.method, route.path, { body: route.body });
            refusalStatusByRoute[`${route.method} ${route.path}`] = refusal.status();
        }

        expect(refusalStatusByRoute).toEqual(Object.fromEntries(Object.keys(refusalStatusByRoute).map(route => [route, 404])));
    });

    test('a channel with UCP switched off answers 404 on its UCP profile', async ({ TestDataService, browser }) => {
        const switchedOffChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(switchedOffChannel.salesChannel.id);
        const switchOffResponse = await TestDataService.saveUcpConfig(switchedOffChannel.salesChannel.id, { active: false });
        expect(switchOffResponse.ok(), await switchOffResponse.text()).toBeTruthy();

        const switchedOffChannelResponse = await fetchRawDiscoveryResponse(browser, switchedOffChannel.url);
        expect(switchedOffChannelResponse.status, switchedOffChannelResponse.body).toBe(404);
    });
});
