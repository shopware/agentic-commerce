import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { UcpSalesChannelDetail } from '@page-objects/UcpSalesChannelDetail';
import { advertisedEndpoints, advertisedTransports, fetchDiscoveryDocument } from '@services/discovery';

test.describe('Agentic Commerce tab configuration @UcpAdmin', () => {
    test('capabilities and transports saved in the tab are what discovery serves', async ({ TestDataService, AdminPage, browser }) => {
        const channel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        const salesChannelId = channel.salesChannel.id;
        await TestDataService.saveUcpConfig(salesChannelId, { active: false, enabledTransports: ['rest', 'a2a'] });
        const ucpSalesChannelDetail = new UcpSalesChannelDetail(AdminPage);

        // Discovery is read once, after the save: the document is publicly cacheable for 60 seconds, so
        // a read before the save would be served again from Shopware's HTTP cache.
        await test.step('the merchant exposes the channel, drops discount and A2A, and adds Embedded', async () => {
            await ucpSalesChannelDetail.openAgenticCommerceTab(salesChannelId);
            await expect(ucpSalesChannelDetail.unsavedBanner).toBeHidden();
            await expect(ucpSalesChannelDetail.transport('a2a')).toBeChecked();
            await expect(ucpSalesChannelDetail.transport('embedded')).not.toBeChecked();
            await expect(ucpSalesChannelDetail.capability('discount')).toBeChecked();

            await ucpSalesChannelDetail.exposeSwitch.check();
            await ucpSalesChannelDetail.capability('discount').uncheck();
            await ucpSalesChannelDetail.transport('a2a').uncheck();
            await ucpSalesChannelDetail.transport('embedded').check();
            await expect(ucpSalesChannelDetail.unsavedBanner).toBeVisible();

            TestDataService.markUcpActivated(salesChannelId);
            await ucpSalesChannelDetail.saveUcpConfig(salesChannelId);
            await expect(ucpSalesChannelDetail.unsavedBanner).toBeHidden();
        });

        await test.step('the selection survives a reload', async () => {
            await ucpSalesChannelDetail.reload(salesChannelId);

            await expect(ucpSalesChannelDetail.exposeSwitch).toBeChecked();
            await expect(ucpSalesChannelDetail.capability('catalog')).toBeChecked();
            await expect(ucpSalesChannelDetail.capability('discount')).not.toBeChecked();
            await expect(ucpSalesChannelDetail.transport('a2a')).not.toBeChecked();
            await expect(ucpSalesChannelDetail.transport('embedded')).toBeChecked();
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
        const ucpSalesChannelDetail = new UcpSalesChannelDetail(AdminPage);
        await ucpSalesChannelDetail.openAgenticCommerceTab(salesChannel.id);

        await test.step('dropping the cart capability and A2A, and adding Embedded, is previewed from the draft', async () => {
            const draftPreview = ucpSalesChannelDetail.waitForDraftPreview(salesChannel.id, draft => draft.enabledCapabilities?.includes('cart') === false
              && draft.enabledTransports?.includes('a2a') === false
              && draft.enabledTransports?.includes('embedded') === true);
            await ucpSalesChannelDetail.capability('cart').uncheck();
            await ucpSalesChannelDetail.transport('a2a').uncheck();
            await ucpSalesChannelDetail.transport('embedded').check();
            await draftPreview;

            await ucpSalesChannelDetail.showSubTab('preview');
            await expect.poll(async () => advertisedTransports(await ucpSalesChannelDetail.renderedPreview())).toEqual(['embedded', 'rest']);
            expect(Object.keys((await ucpSalesChannelDetail.renderedPreview()).capabilities)).not.toContain('dev.ucp.shopping.cart');

            const savedProfile = await TestDataService.getProfilePreview(salesChannel.id);
            expect(advertisedTransports(savedProfile), 'the stored profile is untouched').toEqual(['a2a', 'rest']);
            expect(Object.keys(savedProfile.capabilities)).toContain('dev.ucp.shopping.cart');
        });

        await test.step('after saving, the preview renders the stored profile', async () => {
            await ucpSalesChannelDetail.saveUcpConfig(salesChannel.id);
            await ucpSalesChannelDetail.reload(salesChannel.id);
            await ucpSalesChannelDetail.showSubTab('preview');

            await expect.poll(async () => advertisedTransports(await ucpSalesChannelDetail.renderedPreview())).toEqual(['embedded', 'rest']);
            const savedProfile = await TestDataService.getProfilePreview(salesChannel.id);
            expect(advertisedTransports(savedProfile)).toEqual(['embedded', 'rest']);
            expect(Object.keys(savedProfile.capabilities)).not.toContain('dev.ucp.shopping.cart');
        });
    });

    test('the MCP transport is offered exactly when the Admin API reports Store-API MCP support', async ({ TestDataService, AdminPage, browser }) => {
        const channel = await TestDataService.createStorefrontSalesChannelOnOwnHost();
        await TestDataService.activateUcp(channel.salesChannel.id, { enabledTransports: ['rest'] });
        const ucpSalesChannelDetail = new UcpSalesChannelDetail(AdminPage);

        const meta = await ucpSalesChannelDetail.openAgenticCommerceTab(channel.salesChannel.id);
        expect(typeof meta.supportsStoreApiMcp, 'the sales channel response carries the capability flag').toBe('boolean');

        const offered = await ucpSalesChannelDetail.offeredTransports();
        expect(offered).toContain('rest');
        expect(offered.includes('mcp'), 'MCP is offered exactly when the Admin API reports support').toBe(meta.supportsStoreApiMcp);

        for (const transport of offered) {
            await ucpSalesChannelDetail.transport(transport).check();
        }
        await ucpSalesChannelDetail.saveUcpConfig(channel.salesChannel.id);

        const discovery = (await fetchDiscoveryDocument(browser, channel.url)).ucp;
        expect(advertisedTransports(discovery), 'discovery advertises every offered transport, MCP included when offered')
            .toEqual([...offered].sort());
    });

    test('the unsaved-changes banner tracks the difference to the stored configuration', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(salesChannel.id);
        const ucpSalesChannelDetail = new UcpSalesChannelDetail(AdminPage);
        await ucpSalesChannelDetail.openAgenticCommerceTab(salesChannel.id);

        await expect(ucpSalesChannelDetail.unsavedBanner).toBeHidden();

        await ucpSalesChannelDetail.capability('cart').uncheck();
        await expect(ucpSalesChannelDetail.unsavedBanner).toBeVisible();

        await ucpSalesChannelDetail.showSubTab('preview');
        await expect(ucpSalesChannelDetail.unsavedBanner, 'the banner stays visible on every sub-tab').toBeVisible();

        await ucpSalesChannelDetail.showSubTab('exposure');
        await ucpSalesChannelDetail.capability('cart').check();
        await expect(ucpSalesChannelDetail.unsavedBanner, 'reverting the edit by hand leaves nothing unsaved').toBeHidden();

        await ucpSalesChannelDetail.capability('order').uncheck();
        await expect(ucpSalesChannelDetail.unsavedBanner).toBeVisible();
        await ucpSalesChannelDetail.saveUcpConfig(salesChannel.id);
        await expect(ucpSalesChannelDetail.unsavedBanner).toBeHidden();
        expect((await TestDataService.getUcpConfig(salesChannel.id)).enabledCapabilities).not.toContain('order');
    });

    test('a pasted profile URL is stored as the platform host', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(salesChannel.id, { platformAllowlist: [] });
        const detail = new UcpSalesChannelDetail(AdminPage);
        await detail.openAgenticCommerceTab(salesChannel.id);

        await test.step('the merchant pastes a profile URL and the field keeps its host', async () => {
            expect(await detail.platformHosts()).toEqual([]);
            await detail.addPlatformHost('https://Platform.Example/.well-known/ucp');
            await expect.poll(() => detail.platformHosts()).toEqual(['platform.example']);
            await expect(detail.unsavedBanner).toBeVisible();
        });

        await test.step('an entry that is not a host is refused', async () => {
            await detail.addPlatformHost('not a host');
            await expect.poll(() => detail.platformHosts()).toEqual(['platform.example']);
        });

        await test.step('the host is stored and shown again after a reload', async () => {
            await detail.saveUcpConfig(salesChannel.id);
            expect((await TestDataService.getUcpConfig(salesChannel.id)).platformAllowlist).toEqual(['platform.example']);

            await detail.reload(salesChannel.id);
            await expect.poll(() => detail.platformHosts()).toEqual(['platform.example']);
        });
    });

    test('the merchant switches a sales channel to listed platforms only', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(salesChannel.id, { platformPolicy: 'all', platformAllowlist: [] });
        const detail = new UcpSalesChannelDetail(AdminPage);
        await detail.openAgenticCommerceTab(salesChannel.id);

        await test.step('the merchant chooses listed platforms only', async () => {
            await expect.poll(() => detail.platformPolicy()).toBe('All platforms');
            await detail.selectPlatformPolicy('Listed platforms only');
            await expect.poll(() => detail.platformPolicy()).toBe('Listed platforms only');
            await expect(detail.unsavedBanner).toBeVisible();
        });

        await test.step('the policy is stored and shown again after a reload', async () => {
            await detail.saveUcpConfig(salesChannel.id);
            expect((await TestDataService.getUcpConfig(salesChannel.id)).platformPolicy).toBe('listed');

            await detail.reload(salesChannel.id);
            await expect.poll(() => detail.platformPolicy()).toBe('Listed platforms only');
        });
    });

    test('the tab links the UCP documentation and shows core\'s Agentic files from 6.7.12.0', async ({ InstanceMeta, TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(salesChannel.id);
        const ucpSalesChannelDetail = new UcpSalesChannelDetail(AdminPage);
        await ucpSalesChannelDetail.openAgenticCommerceTab(salesChannel.id);

        await expect(AdminPage.getByRole('link', { name: 'Learn more about the UCP settings in the documentation' }))
            .toHaveAttribute('href', 'https://docs.shopware.com/en/shopware-6-en/extensions/agentic-commerce#ucp-universal-commerce-protocol');

        const agenticFilesHeading = AdminPage.getByRole('heading', { name: 'Agentic files' });
        if (satisfies(InstanceMeta.version, '>=6.7.12.0')) {
            await expect(agenticFilesHeading).toBeVisible();
        }
        else {
            await expect(agenticFilesHeading, 'core ships no Agentic files view before 6.7.12.0').toBeHidden();
        }
    });

    test.fixme('leaving the tab with unsaved changes asks before discarding them', () => {
        // Neither the plugin nor core guards the sales channel detail page with a leave
        // confirmation on any supported lane; this records the missing behaviour until it exists.
    });
});
