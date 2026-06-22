import { expect, test } from '@playwright/test';
import {
    assertProfileTransports,
    attachBrowserFailureCollectors,
    createAdminApiContext,
    firstSalesChannel,
    laneConfig,
    loginAdmin,
    withRestoredUcpConfig,
} from '../fixtures/shopware.js';

test.describe('UCP administration detail', () => {
    test('renders detail and keeps native sales-channel configuration reachable', async ({ page }) => {
        const config = laneConfig();
        const browserFailures = attachBrowserFailureCollectors(page);
        const adminApi = await createAdminApiContext(config);
        const { salesChannel } = await firstSalesChannel(adminApi);

        await loginAdmin(page, config);

        await page.goto(`/admin#/sw/sales/channel/detail/${salesChannel.id}/base`);
        await expect(page.locator('.sw-sales-channel-detail').first()).toBeVisible({ timeout: 120_000 });
        await expect(page.getByText('Agent access', { exact: true })).toBeVisible();
        await expect(page.getByText('Configure UCP')).toBeVisible();
        await expect(page.getByText('API access', { exact: true })).toBeVisible();

        await page.goto(`/admin#/sw/settings/ucp/detail/${salesChannel.id}`);
        await expect(page.locator('.sw-settings-ucp-detail').first()).toBeVisible({ timeout: 120_000 });
        await expect(page.locator('.sw-settings-ucp-detail__title', { hasText: salesChannel.name })).toBeVisible();
        await expect(page.getByText('Exposure and profile', { exact: true })).toBeVisible();
        await expect(page.getByText('Security and delivery', { exact: true })).toBeVisible();
        await expect(page.getByText('Capabilities', { exact: true })).toBeVisible();
        await expect(page.getByText('Transports', { exact: true })).toBeVisible();
        await expect(page.getByText('Signing keys', { exact: true })).toBeVisible();

        const columnCount = await page.locator('.sw-settings-ucp-detail__columns').evaluate((element) => (
            getComputedStyle(element).gridTemplateColumns.split(' ').filter(Boolean).length
        )).catch(() => 1);
        expect(columnCount, 'UCP detail must use a one-column layout on every lane').toBe(1);

        browserFailures.assertClean();
        await adminApi.dispose();
    });

    test('saves config and validates profile preview transports', async () => {
        const config = laneConfig();
        const adminApi = await createAdminApiContext(config);
        const { salesChannel, payload } = await firstSalesChannel(adminApi);
        const expectedTransports = payload.meta?.supportsStoreApiMcp === true
            ? ['rest', 'a2a', 'embedded', 'mcp']
            : ['rest', 'a2a', 'embedded'];

        await withRestoredUcpConfig(adminApi, salesChannel.id, async (originalConfig) => {
            const qaConfig = {
                ...originalConfig,
                active: true,
                enabledTransports: expectedTransports,
                remoteProfileAllowlist: ['agent-platform.example'],
                agentAllowlist: ['agent.example'],
                webhookUrlOverride: 'https://agent.example/ucp/webhook',
                embeddedAllowedOrigins: ['https://assistant.example'],
                embeddedFrameAncestors: ['https://assistant.example'],
                signaturePolicy: 'strict',
            };

            const saveResponse = await adminApi.put(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`, { data: qaConfig });
            await expect(saveResponse, await saveResponse.text()).toBeOK();

            const previewResponse = await adminApi.get(`/api/_admin/ucp/sales-channels/${salesChannel.id}/profile-preview`);
            await expect(previewResponse, await previewResponse.text()).toBeOK();
            await assertProfileTransports((await previewResponse.json()).data, expectedTransports);
        });

        await adminApi.dispose();
    });
});
