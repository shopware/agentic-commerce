import { expect, test } from '@playwright/test';
import {
    assertSettingsItemRegistered,
    attachBrowserFailureCollectors,
    laneConfig,
    loginAdmin,
} from '../fixtures/shopware.js';

test.describe('UCP administration overview', () => {
    test('loads settings entry and sales-channel overview', async ({ page, request }) => {
        const config = laneConfig();
        const browserFailures = attachBrowserFailureCollectors(page);

        await loginAdmin(page, config);

        await page.goto('/admin#/sw/settings/index');
        await expect(page.locator('.sw-settings-index').first()).toBeVisible({ timeout: 120_000 });
        await assertSettingsItemRegistered(page);

        await page.goto('/admin#/sw/settings/ucp/index');
        await expect(page.locator('.sw-settings-ucp-index').first()).toBeVisible({ timeout: 120_000 });
        await expect(page.getByRole('heading', { name: 'UCP' }).first()).toBeVisible();
        await expect(page.getByText(config.expectedVersionLabel)).toBeVisible();
        await expect(page.getByText('Active sales channels', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Configure UCP').first()).toBeVisible();
        await expect(page.getByText('Open sales channel')).toHaveCount(0);
        await expect(page.getByText('Configure sales channel').first()).toBeVisible();

        const publicApi = request;
        const response = await publicApi.get('/api/_admin/ucp/sales-channels', {
            failOnStatusCode: false,
        });
        expect(response.status(), 'admin API must not be public').toBeGreaterThanOrEqual(401);

        browserFailures.assertClean();
    });

    test('opens detail from the overview action', async ({ page }) => {
        const config = laneConfig();
        const browserFailures = attachBrowserFailureCollectors(page);

        await loginAdmin(page, config);
        await page.goto('/admin#/sw/settings/ucp/index');
        await expect(page.locator('.sw-settings-ucp-index').first()).toBeVisible({ timeout: 120_000 });

        await page.getByText('Configure UCP', { exact: true }).first().click();

        await expect(page.locator('.sw-settings-ucp-detail').first()).toBeVisible({ timeout: 120_000 });
        await expect(page.getByText('Exposure and profile', { exact: true })).toBeVisible();
        await expect(page.getByText('Signing keys', { exact: true })).toBeVisible();
        await expect(page.getByText('Open sales channel')).toHaveCount(0);
        await expect(page.getByText('Configure sales channel').first()).toBeVisible();

        browserFailures.assertClean();
    });
});
