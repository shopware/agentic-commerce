import { expect, test } from '@fixtures/AcceptanceTest';
import { FEED_SALES_CHANNEL_TYPE_ID, UCP_SALES_CHANNEL_TYPE_NOT_SUPPORTED, UcpTestDataService } from '@services/UcpTestDataService';
import type { UcpProfileDocument } from '@services/UcpTestDataService';
import { readAdminPrivilegeMapping, resolveRole } from '@services/pluginSource';
import { UCP_ROLES } from '@fixtures/UcpAclUsers';

test.describe('Isolated UCP test data @Setup', () => {
    test('the worker channel sits on its own path-prefixed domain', ({ DefaultSalesChannel, SalesChannelBaseConfig }) => {
        expect(DefaultSalesChannel.url).toMatch(/\/test-[0-9a-f]{32}\/$/);
        expect(DefaultSalesChannel.salesChannel.typeId).toBe(SalesChannelBaseConfig.storefrontTypeId);
    });

    test('activating one channel leaves the others untouched', async ({ TestDataService, DefaultSalesChannel }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannel();
        expect(activatedChannel.url).not.toEqual(DefaultSalesChannel.url);

        const activatedConfig = await TestDataService.activateUcp(activatedChannel.salesChannel.id);
        expect(activatedConfig.active).toBe(true);

        const untouchedConfig = await TestDataService.getUcpConfig(DefaultSalesChannel.salesChannel.id);
        expect(untouchedConfig.active).toBe(false);

        const channelsOfferedUcp = await TestDataService.listUcpSalesChannels();
        expect(channelsOfferedUcp.find(entry => entry.id === activatedChannel.salesChannel.id)?.ucp).toMatchObject({ active: true });
    });

    test('a headless channel is offered UCP', async ({ TestDataService }) => {
        const headlessChannel = await TestDataService.createHeadlessSalesChannel();

        const channelsOfferedUcp = await TestDataService.listUcpSalesChannels();
        expect(channelsOfferedUcp.map(entry => entry.id)).toContain(headlessChannel.salesChannel.id);

        const headlessConfig = await TestDataService.activateUcp(headlessChannel.salesChannel.id);
        expect(headlessConfig.active).toBe(true);
    });

    test('a product-feed channel is not offered UCP and refuses activation', async ({ TestDataService }) => {
        const feedChannel = await TestDataService.createFeedSalesChannel();
        expect(feedChannel.salesChannel.typeId).toBe(FEED_SALES_CHANNEL_TYPE_ID);

        const channelsOfferedUcp = await TestDataService.listUcpSalesChannels();
        expect(channelsOfferedUcp.map(entry => entry.id)).not.toContain(feedChannel.salesChannel.id);

        const refusal = await TestDataService.saveUcpConfig(feedChannel.salesChannel.id, { active: true });
        expect(refusal.status()).toBe(400);
        expect(await UcpTestDataService.refusalCode(refusal)).toBe(UCP_SALES_CHANNEL_TYPE_NOT_SUPPORTED);
    });
});

test.describe('Test agent profile host @Setup', () => {
    test('publishes a profile the shop can fetch, with the agent\'s public key', async ({ UcpAgentProfileHost, page }) => {
        const agent = await UcpAgentProfileHost.publish({ label: 'setup-agent' });

        expect(agent.profileUrl).toContain(`/ucp-acceptance-agents/${agent.kid}.json`);
        expect(agent.agentHeader).toBe(`setup-agent; profile="${agent.profileUrl}"`);
        expect(agent.privateKeyPem).toContain('BEGIN PRIVATE KEY');

        const servedProfile = await page.request.get(new URL(`ucp-acceptance-agents/${agent.kid}.json`, process.env.APP_URL).toString());
        expect(servedProfile.ok(), await servedProfile.text()).toBeTruthy();

        const publishedProfile = (await servedProfile.json()) as UcpProfileDocument;
        expect(publishedProfile.signing_keys).toEqual([expect.objectContaining({ kid: agent.kid, alg: 'ES256', crv: 'P-256' })]);
        expect(publishedProfile.ucp.version).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    test('the published profile negotiates every capability an activated channel serves', async ({ UcpAgentProfileHost, TestDataService, page }) => {
        const negotiatingChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(negotiatingChannel.salesChannel.id);
        const servedByChannel = await TestDataService.getProfilePreview(negotiatingChannel.salesChannel.id);

        const agent = await UcpAgentProfileHost.publish();
        const servedProfile = await page.request.get(new URL(`ucp-acceptance-agents/${agent.kid}.json`, process.env.APP_URL).toString());
        const publishedProfile = ((await servedProfile.json()) as UcpProfileDocument).ucp;

        expect(publishedProfile.version, 'the SDK refuses a profile at another protocol version').toBe(servedByChannel.version);
        expect(Object.keys(servedByChannel.capabilities).length).toBeGreaterThan(0);

        const negotiated = Object.keys(servedByChannel.capabilities).filter(name => (publishedProfile.capabilities[name] ?? [])
            .some(agentEntry => servedByChannel.capabilities[name].some(channelEntry => channelEntry.version === agentEntry.version)));
        expect(negotiated.sort(), 'every capability the channel serves survives the SDK\'s intersection')
            .toEqual(Object.keys(servedByChannel.capabilities).sort());

        for (const name of negotiated) {
            const bases = publishedProfile.capabilities[name][0].extends ?? [];
            expect(bases.length === 0 || bases.some(base => negotiated.includes(base)), `${name} extends a capability that did not survive`).toBe(true);
        }
    });

    test('a capabilities override replaces the default set', async ({ UcpAgentProfileHost, page }) => {
        const agentWithoutCapabilities = await UcpAgentProfileHost.publish({ capabilities: {} });

        const servedProfile = await page.request.get(new URL(`ucp-acceptance-agents/${agentWithoutCapabilities.kid}.json`, process.env.APP_URL).toString());

        expect(((await servedProfile.json()) as UcpProfileDocument).ucp.capabilities).toEqual({});
    });
});

test.describe('UCP privilege mapping @Setup', () => {
    test('a second read in the same worker still yields every UCP role', async () => {
        const firstRead = await readAdminPrivilegeMapping();
        const repeatedRead = await readAdminPrivilegeMapping();

        expect(repeatedRead.size).toBeGreaterThan(0);
        expect(repeatedRead).toBe(firstRead);
        for (const role of UCP_ROLES) {
            expect(resolveRole(repeatedRead, role).keys).toContain(role);
        }
    });
});

test.describe('UCP ACL users @Setup', () => {
    test('a ucp.viewer reaches the Administration with the role attached', async ({ UcpAclUsers, TestDataService }) => {
        const viewer = await UcpAclUsers.as('ucp.viewer');

        expect(viewer.privileges).toContain('ucp.viewer');
        expect(viewer.privileges).not.toContain('ucp.editor');
        expect(viewer.privileges).toContain('sales_channel:read');

        const storedUser = await TestDataService.getUserById(viewer.user.id);
        expect(storedUser.admin).toBe(false);
        await expect(viewer.page.locator('.sw-admin-menu')).toBeVisible();
    });
});

test.describe('Console wrapper @Setup @UcpConsole', () => {
    test('manages signing keys for a sales channel through bin/console', async ({ UcpConsole, TestDataService }) => {
        expect(UcpConsole.isAvailable(), `bin/console is not reachable via ${UcpConsole.describe()}; set UCP_CONSOLE`).toBe(true);

        const signingKeyChannel = await TestDataService.createStorefrontSalesChannel();
        const kid = `acceptance-${Date.now()}`;

        UcpConsole.generateSigningKey(signingKeyChannel.salesChannel.id, kid);
        expect(UcpConsole.listSigningKeys(signingKeyChannel.salesChannel.id).map(key => key.kid)).toContain(kid);

        UcpConsole.retireSigningKey(signingKeyChannel.salesChannel.id, kid);
        UcpConsole.deleteSigningKey(signingKeyChannel.salesChannel.id, kid);
        expect(UcpConsole.listSigningKeys(signingKeyChannel.salesChannel.id).map(key => key.kid)).not.toContain(kid);
    });
});
