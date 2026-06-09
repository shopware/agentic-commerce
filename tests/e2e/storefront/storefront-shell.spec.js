import { expect, test } from '@playwright/test';

test.describe('Storefront shell', () => {
    test('renders homepage and cart with theme assets', async ({ page }) => {
        const failures = [];

        page.on('console', (message) => {
            if (message.type() === 'error' && !/^Failed to load resource:/i.test(message.text())) {
                failures.push(`console: ${message.text()}`);
            }
        });
        page.on('pageerror', (error) => {
            failures.push(`pageerror: ${error.message}`);
        });
        page.on('requestfailed', (request) => {
            if (/\.(js|css)|\/theme\//i.test(request.url())) {
                failures.push(`${request.failure()?.errorText || 'request failed'}: ${request.url()}`);
            }
        });

        await page.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.header-main').first()).toBeVisible({ timeout: 60_000 });
        await expect(page.locator('body')).toHaveClass(/is-ctl-navigation/);

        const assetState = await page.evaluate(async () => {
            const themeAssetsPublicPath = globalThis.themeAssetsPublicPath;
            const themeJsPublicPath = globalThis.themeJsPublicPath;

            if (themeAssetsPublicPath) {
                return { themeAssetsPublicPath };
            }

            if (themeJsPublicPath) {
                return { themeJsPublicPath };
            }

            return null;
        });
        expect(assetState, 'storefront must expose theme asset bootstrap paths').not.toBeNull();

        await page.goto('/checkout/cart', { waitUntil: 'domcontentloaded' });
        await expect(page.getByText('Shopping cart', { exact: false }).or(page.getByText('Warenkorb', { exact: false })).first()).toBeVisible({ timeout: 60_000 });

        expect(failures, 'storefront JS/CSS/theme request failures').toEqual([]);
    });
});
