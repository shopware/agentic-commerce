import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Opens an Administration route the way the ATS `ShopAdmin.goesTo()` does: on a page that already
 * runs the Administration, by changing the hash instead of loading the document again, so the
 * navigation cannot race the Administration's own start-up routing.
 */
export async function goToAdminRoute(page: Page, route: string): Promise<void> {
    // ADMIN_URL ends in `admin/`, the running Administration sits at `admin#/...`.
    const adminUrl = (process.env.ADMIN_URL ?? '').replace(/\/+$/, '');
    const path = route.split('?')[0];

    if (page.url().startsWith(`${adminUrl}#`) || page.url().startsWith(`${adminUrl}/#`)) {
        await page.evaluate(`document.location.hash = ${JSON.stringify(route)};`);
    }
    else {
        await page.goto(`${adminUrl}/#${route}`);
    }

    await page.waitForURL(url => url.hash.startsWith(`#${path}`));
    await expect(page.locator('.sw-skeleton')).toHaveCount(0);
}
