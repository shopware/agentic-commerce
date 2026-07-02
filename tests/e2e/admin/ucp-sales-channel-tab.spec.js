import { expect, test } from '@playwright/test';
import {
    attachBrowserFailureCollectors,
    createAdminApiContext,
    firstSalesChannel,
    laneConfig,
    loginAdmin,
    withRestoredUcpConfig,
} from '../fixtures/shopware.js';

test.describe('UCP sales-channel admin tab', () => {
    test('renders controls, preview, and available Agentic files surface', async ({ page }) => {
        const config = laneConfig();
        const adminApi = await createAdminApiContext(config);
        const { salesChannel, payload } = await firstSalesChannel(adminApi);
        const expectedTransports = payload.meta?.supportsStoreApiMcp === true
            ? ['REST', 'A2A', 'Embedded', 'MCP']
            : ['REST', 'A2A', 'Embedded'];
        const browserFailures = attachBrowserFailureCollectors(page);

        await withRestoredUcpConfig(adminApi, salesChannel.id, async (originalConfig) => {
            const saveResponse = await adminApi.put(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`, {
                data: {
                    ...originalConfig,
                    active: true,
                    enabledTransports: expectedTransports.map((transport) => transport.toLowerCase()),
                },
            });
            await expect(saveResponse, await saveResponse.text()).toBeOK();

            await loginAdmin(page, config);
            await page.goto(`/admin#/sw/sales/channel/detail/${salesChannel.id}/agentic-commerce`, { waitUntil: 'domcontentloaded' });

            const tabRoot = page.locator('.sw-sales-channel-detail-agentic-commerce').first();
            await expect(tabRoot).toBeVisible({ timeout: 120_000 });

            await expect(tabRoot.getByText('UCP', { exact: true })).toBeVisible();
            await expect(tabRoot.getByText('Expose via UCP', { exact: true })).toBeVisible();
            await expect(tabRoot.getByText('Browse catalog', { exact: true })).toBeVisible();
            await expect(tabRoot.getByText('Cart', { exact: true })).toBeVisible();
            await expect(tabRoot.getByText('Checkout', { exact: true })).toBeVisible();
            await expectAlignedOptionGrid(tabRoot, expectedTransports.length);

            for (const transport of expectedTransports) {
                await expect(tabRoot.getByText(transport, { exact: true })).toBeVisible();
            }

            const previewTab = tabRoot.getByText('Preview', { exact: true });
            if (await previewTab.isVisible().catch(() => false)) {
                await previewTab.click();
            }

            const preview = tabRoot.locator('.sw-sales-channel-detail-agentic-commerce__preview').first();
            await expect(preview).toBeVisible();
            await expect(preview).toContainText('"ucp"');

            if (config.lane === 'trunk') {
                await expect(tabRoot.getByText('Agentic files', { exact: true })).toBeVisible();
                await expect(tabRoot.getByText(/AI-readable files|llms\.txt/).first()).toBeVisible();
            } else {
                await expect(tabRoot.getByText(/AI-readable files|llms\.txt/).first()).toBeHidden();
            }

            browserFailures.assertClean();
        });

        await adminApi.dispose();
    });
});

async function expectAlignedOptionGrid(tabRoot, expectedTransportCount) {
    const optionGrid = tabRoot.locator('.sw-sales-channel-detail-agentic-commerce__option-grid').first();
    await expect(optionGrid).toBeVisible();
    await expect(optionGrid.locator('.sw-sales-channel-detail-agentic-commerce__option-column')).toHaveCount(2);

    const optionLists = optionGrid.locator('.sw-sales-channel-detail-agentic-commerce__option-list');
    const capabilityRows = optionLists.nth(0).locator('.sw-sales-channel-detail-agentic-commerce__option-item');
    const transportRows = optionLists.nth(1).locator('.sw-sales-channel-detail-agentic-commerce__option-item');

    await expect(capabilityRows).toHaveCount(5);
    await expect(transportRows).toHaveCount(expectedTransportCount);

    const [capabilityBoxes, transportBoxes] = await Promise.all([
        rowBoxes(capabilityRows),
        rowBoxes(transportRows),
    ]);

    expect(transportBoxes[0].x).toBeGreaterThan(capabilityBoxes[0].x + capabilityBoxes[0].width);

    for (let index = 0; index < Math.min(capabilityBoxes.length, transportBoxes.length); index += 1) {
        expect(Math.abs(capabilityBoxes[index].y - transportBoxes[index].y)).toBeLessThanOrEqual(2);
    }
}

async function rowBoxes(locator) {
    return locator.evaluateAll((items) => items.map((item) => {
        const rect = item.getBoundingClientRect();

        return {
            x: rect.x,
            y: rect.y,
            width: rect.width,
            height: rect.height,
        };
    }));
}
