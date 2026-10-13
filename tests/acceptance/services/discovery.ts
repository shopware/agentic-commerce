import { expect } from '@playwright/test';
import type { Browser } from '@playwright/test';
import type { UcpProfileDocument } from './UcpTestDataService';

const ignoreHTTPSErrors = ['1', 'true'].includes(process.env.SHOPWARE_PLAYWRIGHT_IGNORE_HTTPS_ERRORS ?? '');

/**
 * The real `/.well-known/ucp` of a channel, fetched like an agent would, in a fresh browser context
 * so the browser cache plays no part. Shopware's HTTP cache still serves the document for up to 60
 * seconds, so read it after the change under test, not before. A browser is needed: only it resolves
 * the per-channel hosts of `createStorefrontSalesChannelOnOwnHost()`.
 */
export async function fetchDiscoveryDocument(browser: Browser, baseUrl: string): Promise<UcpProfileDocument> {
    const context = await browser.newContext({ ignoreHTTPSErrors });
    try {
        const page = await context.newPage();
        const response = await page.goto(`${baseUrl.replace(/\/+$/, '')}/.well-known/ucp`);
        expect(response, `no response for ${baseUrl}.well-known/ucp`).not.toBeNull();
        expect(response!.ok(), await response!.text()).toBeTruthy();

        return (await response!.json()) as UcpProfileDocument;
    }
    finally {
        await context.close();
    }
}

/** Transports a profile advertises services on. */
export function advertisedTransports(profile: UcpProfileDocument['ucp']): string[] {
    return Object.values(profile.services).flat().map(service => service.transport).sort();
}

/** Every endpoint a profile advertises. */
export function advertisedEndpoints(profile: UcpProfileDocument['ucp']): string[] {
    return Object.values(profile.services).flat().map(service => service.endpoint);
}
