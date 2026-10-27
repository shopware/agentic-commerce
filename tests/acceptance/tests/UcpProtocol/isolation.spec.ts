import { expect, test } from '@fixtures/AcceptanceTest';
import type { APIResponse } from '@playwright/test';
import { advertisedEndpoints, fetchDiscoveryDocument } from '@services/discovery';
import { CARTS_PATH, shoppingRoutes } from '@services/shoppingRoutes';

async function messageCodes(response: APIResponse): Promise<string[]> {
    const { messages = [] } = (await response.json()) as { messages?: { code?: string }[] };

    return messages.map(message => message.code ?? '');
}

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
});
