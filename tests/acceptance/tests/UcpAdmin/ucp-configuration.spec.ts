import { expect, test } from '@fixtures/AcceptanceTest';
import { UcpSalesChannelDetail } from '@page-objects/UcpSalesChannelDetail';
import { advertisedEndpoints, advertisedTransports, fetchDiscoveryDocument } from '@services/discovery';

test.describe('Agentic Commerce tab configuration @UcpAdmin', () => {
    test('capabilities and transports saved in the tab are what discovery serves', async ({ TestDataService, AdminPage, browser }) => {
        const channel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        const salesChannelId = channel.salesChannel.id;
        await TestDataService.saveUcpConfig(salesChannelId, { active: false, enabledTransports: ['rest', 'a2a'] });
        const detail = new UcpSalesChannelDetail(AdminPage);

        // Discovery is read once, after the save: the document is publicly cacheable for 60 seconds, so
        // a read before the save would be served again from Shopware's HTTP cache.
        await test.step('the merchant exposes the channel, drops discount and A2A, and adds Embedded', async () => {
            await detail.openAgenticCommerceTab(salesChannelId);
            await expect(detail.unsavedBanner).toBeHidden();
            await expect(detail.transport('a2a')).toBeChecked();
            await expect(detail.transport('embedded')).not.toBeChecked();
            await expect(detail.capability('discount')).toBeChecked();

            await detail.exposeSwitch.check();
            await detail.capability('discount').uncheck();
            await detail.transport('a2a').uncheck();
            await detail.transport('embedded').check();
            await expect(detail.unsavedBanner).toBeVisible();

            TestDataService.markUcpActivated(salesChannelId);
            await detail.saveUcpConfig(salesChannelId);
            await expect(detail.unsavedBanner).toBeHidden();
        });

        await test.step('the selection survives a reload', async () => {
            await detail.reload(salesChannelId);

            await expect(detail.exposeSwitch).toBeChecked();
            await expect(detail.capability('catalog')).toBeChecked();
            await expect(detail.capability('discount')).not.toBeChecked();
            await expect(detail.transport('a2a')).not.toBeChecked();
            await expect(detail.transport('embedded')).toBeChecked();
        });

        await test.step('discovery serves the saved selection on the channel\'s own host', async () => {
            const discovery = (await fetchDiscoveryDocument(browser, channel.url)).ucp;

            expect(advertisedTransports(discovery)).toEqual(['embedded', 'rest']);
            expect(Object.keys(discovery.capabilities)).toContain('dev.ucp.shopping.catalog.search');
            expect(Object.keys(discovery.capabilities)).not.toContain('dev.ucp.shopping.discount');
            for (const endpoint of advertisedEndpoints(discovery)) {
                expect(endpoint).toContain(channel.url);
            }
        });
    });

    test('the preview renders the unsaved draft, then the saved profile', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(salesChannel.id, { enabledTransports: ['rest', 'a2a'] });
        const detail = new UcpSalesChannelDetail(AdminPage);
        await detail.openAgenticCommerceTab(salesChannel.id);

        await test.step('dropping the cart capability and A2A, and adding Embedded, is previewed from the draft', async () => {
            const draftPreview = detail.waitForDraftPreview(salesChannel.id, draft => draft.enabledCapabilities?.includes('cart') === false
              && draft.enabledTransports?.includes('a2a') === false
              && draft.enabledTransports?.includes('embedded') === true);
            await detail.capability('cart').uncheck();
            await detail.transport('a2a').uncheck();
            await detail.transport('embedded').check();
            await draftPreview;

            await detail.showSubTab('preview');
            await expect.poll(async () => advertisedTransports(await detail.renderedPreview())).toEqual(['embedded', 'rest']);
            expect(Object.keys((await detail.renderedPreview()).capabilities)).not.toContain('dev.ucp.shopping.cart');

            const savedProfile = await TestDataService.getProfilePreview(salesChannel.id);
            expect(advertisedTransports(savedProfile), 'the stored profile is untouched').toEqual(['a2a', 'rest']);
            expect(Object.keys(savedProfile.capabilities)).toContain('dev.ucp.shopping.cart');
        });

        await test.step('after saving, the preview renders the stored profile', async () => {
            await detail.saveUcpConfig(salesChannel.id);
            await detail.reload(salesChannel.id);
            await detail.showSubTab('preview');

            await expect.poll(async () => advertisedTransports(await detail.renderedPreview())).toEqual(['embedded', 'rest']);
            const savedProfile = await TestDataService.getProfilePreview(salesChannel.id);
            expect(advertisedTransports(savedProfile)).toEqual(['embedded', 'rest']);
            expect(Object.keys(savedProfile.capabilities)).not.toContain('dev.ucp.shopping.cart');
        });
    });

    test('the MCP transport is offered exactly when the Admin API reports Store-API MCP support', async ({ TestDataService, AdminPage, browser }) => {
        const channel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(channel.salesChannel.id, { enabledTransports: ['rest'] });
        const detail = new UcpSalesChannelDetail(AdminPage);

        const meta = await detail.openAgenticCommerceTab(channel.salesChannel.id);
        expect(typeof meta.supportsStoreApiMcp, 'the sales channel response carries the capability flag').toBe('boolean');

        const offered = await detail.offeredTransports();
        expect(offered).toContain('rest');
        expect(offered.includes('mcp'), 'MCP is offered exactly when the Admin API reports support').toBe(meta.supportsStoreApiMcp);

        for (const transport of offered) {
            await detail.transport(transport).check();
        }
        await detail.saveUcpConfig(channel.salesChannel.id);

        const discovery = (await fetchDiscoveryDocument(browser, channel.url)).ucp;
        expect(advertisedTransports(discovery), 'discovery advertises every offered transport, MCP included when offered')
            .toEqual([...offered].sort());
    });

    test('the unsaved-changes banner tracks the difference to the stored configuration', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(salesChannel.id);
        const detail = new UcpSalesChannelDetail(AdminPage);
        await detail.openAgenticCommerceTab(salesChannel.id);

        await expect(detail.unsavedBanner).toBeHidden();

        await detail.capability('cart').uncheck();
        await expect(detail.unsavedBanner).toBeVisible();

        await detail.showSubTab('preview');
        await expect(detail.unsavedBanner, 'the banner stays visible on every sub-tab').toBeVisible();

        await detail.showSubTab('exposure');
        await detail.capability('cart').check();
        await expect(detail.unsavedBanner, 'reverting the edit by hand leaves nothing unsaved').toBeHidden();

        await detail.capability('order').uncheck();
        await expect(detail.unsavedBanner).toBeVisible();
        await detail.saveUcpConfig(salesChannel.id);
        await expect(detail.unsavedBanner).toBeHidden();
        expect((await TestDataService.getUcpConfig(salesChannel.id)).enabledCapabilities).not.toContain('order');
    });

    test.fixme('leaving the tab with unsaved changes asks before discarding them', () => {
        // Neither the plugin nor core guards the sales channel detail page with a leave
        // confirmation on any supported lane; this records the missing behaviour until it exists.
    });
});
