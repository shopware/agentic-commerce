import { expect, test } from '@fixtures/AcceptanceTest';
import { advertisedEndpoints, fetchAnswerByRoute, fetchDiscoveryDocument } from '@services/discovery';
import { CARTS_PATH, shoppingRoutes } from '@services/shoppingRoutes';
import { messageCodes } from '@services/ucpMessages';

test.describe('Sales channel isolation @UcpProtocol', () => {
    test('a cart created on one channel is not found on another', async ({ TestDataService, UcpAgent }) => {
        const cartOwningChannel = await TestDataService.createStorefrontSalesChannel();
        const nonOwningChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(cartOwningChannel.salesChannel.id);
        await TestDataService.activateUcp(nonOwningChannel.salesChannel.id);
        const productInBothChannels = await TestDataService.createBasicProduct({
            visibilities: [
                { salesChannelId: cartOwningChannel.salesChannel.id, visibility: 30 },
                { salesChannelId: nonOwningChannel.salesChannel.id, visibility: 30 },
            ],
        });

        const createdCart = await UcpAgent.at(cartOwningChannel.url).rest.send('POST', CARTS_PATH, {
            body: { line_items: [{ item: { id: productInBothChannels.id }, quantity: 1 }] },
        });
        expect(createdCart.ok(), await createdCart.text()).toBeTruthy();
        const { id: cartId } = (await createdCart.json()) as { id: string };

        const cartReadOnOwningChannel = await UcpAgent.at(cartOwningChannel.url).rest.send('GET', `${CARTS_PATH}/${cartId}`);
        expect(cartReadOnOwningChannel.status(), await cartReadOnOwningChannel.text()).toBe(200);

        const cartReadOnNonOwningChannel = await UcpAgent.at(nonOwningChannel.url).rest.send('GET', `${CARTS_PATH}/${cartId}`);
        expect(await messageCodes(cartReadOnNonOwningChannel), await cartReadOnNonOwningChannel.text()).toContain('not_found');
        expect(await cartReadOnNonOwningChannel.text()).not.toContain(productInBothChannels.id);
    });

    test('a channel with UCP switched off serves none of the shopping routes', async ({ TestDataService, UcpAgent }) => {
        const untouchedChannel = await TestDataService.createStorefrontSalesChannel();
        const switchedOffChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(untouchedChannel.salesChannel.id);
        await TestDataService.activateUcp(switchedOffChannel.salesChannel.id);
        const switchOffResponse = await TestDataService.saveUcpConfig(switchedOffChannel.salesChannel.id, { active: false });
        expect(switchOffResponse.ok(), await switchOffResponse.text()).toBeTruthy();

        const searchOnUntouchedChannel = await UcpAgent.at(untouchedChannel.url).rest.send('POST', '/ucp/v1/catalog/search', { body: { query: 'product' } });
        expect(searchOnUntouchedChannel.status(), await searchOnUntouchedChannel.text()).toBe(200);

        for (const route of shoppingRoutes) {
            const refusal = await UcpAgent.at(switchedOffChannel.url).rest.send(route.method, route.path, { body: route.body });

            expect(refusal.ok(), `${route.method} ${route.path}: ${refusal.status()} ${await refusal.text()}`).toBeFalsy();
        }
    });

    test('a channel with UCP switched off advertises no services or capabilities', async ({ TestDataService, browser }) => {
        const untouchedChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        const switchedOffChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(untouchedChannel.salesChannel.id);
        await TestDataService.activateUcp(switchedOffChannel.salesChannel.id);
        const switchOffResponse = await TestDataService.saveUcpConfig(switchedOffChannel.salesChannel.id, { active: false });
        expect(switchOffResponse.ok(), await switchOffResponse.text()).toBeTruthy();

        const untouchedChannelUcp = (await fetchDiscoveryDocument(browser, untouchedChannel.url)).ucp;
        expect(advertisedEndpoints(untouchedChannelUcp)).not.toHaveLength(0);
        const switchedOffChannelUcp = (await fetchDiscoveryDocument(browser, switchedOffChannel.url)).ucp;
        expect(switchedOffChannelUcp.services).toEqual({});
        expect(switchedOffChannelUcp.capabilities).toEqual({});
    });

    test('an inactive sales channel serves neither its UCP profile nor any shopping route', async ({ TestDataService, browser }) => {
        const untouchedChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        const deactivatedChannel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(untouchedChannel.salesChannel.id);
        await TestDataService.activateUcp(deactivatedChannel.salesChannel.id);
        const deactivationResult = await TestDataService.AdminApiClient.patch(`./sales-channel/${deactivatedChannel.salesChannel.id}`, { data: { active: false } });
        expect(deactivationResult.ok(), await deactivationResult.text()).toBeTruthy();
        const ucpRoutes = [{ method: 'GET', path: '/.well-known/ucp' }, ...shoppingRoutes];

        const untouchedChannelAnswerByRoute = await fetchAnswerByRoute(browser, untouchedChannel.url, ucpRoutes);
        const routesUntouchedChannelLeavesToCore = Object.entries(untouchedChannelAnswerByRoute).filter(([, answer]) => !answer.contentType.includes('json')).map(([route]) => route);
        expect(routesUntouchedChannelLeavesToCore, JSON.stringify(untouchedChannelAnswerByRoute, null, 2)).toEqual([]);

        const answerByRoute = await fetchAnswerByRoute(browser, deactivatedChannel.url, ucpRoutes);
        const jsonAnswerByRoute = Object.fromEntries(Object.entries(answerByRoute).filter(([, answer]) => answer.contentType.includes('json')));
        // Core answers for the inactive channel, with a status that differs between Shopware versions.
        expect(jsonAnswerByRoute, JSON.stringify(answerByRoute, null, 2)).toEqual({});
    });
});
