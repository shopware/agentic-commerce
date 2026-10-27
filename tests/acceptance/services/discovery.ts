import { expect } from '@playwright/test';
import type { Browser, Response } from '@playwright/test';
import type { UcpProfileDocument } from './UcpTestDataService';

const ignoreHTTPSErrors = ['1', 'true'].includes(process.env.SHOPWARE_PLAYWRIGHT_IGNORE_HTTPS_ERRORS ?? '');

const OWN_HOST_REQUIREMENT = 'A channel on a host of its own needs the web server to answer any Host (a catch-all vhost) '
  + 'and TRUSTED_HOSTS, when set, to admit *.ucp-acceptance.test.';

/**
 * The real `/.well-known/ucp` of a channel, fetched like an agent would, in a fresh browser context
 * so the browser cache plays no part. Shopware's HTTP cache still serves the document for up to 60
 * seconds, so read it after the change under test, not before. A browser is needed: only it resolves
 * the per-channel hosts of `createStorefrontSalesChannelOnOwnHost()`.
 */
export async function fetchDiscoveryDocument(browser: Browser, baseUrl: string): Promise<UcpProfileDocument> {
    return (await fetchDiscoveryResponse(browser, baseUrl)).document;
}

export async function fetchDiscoveryResponse(browser: Browser, baseUrl: string): Promise<{ headers: Record<string, string>, document: UcpProfileDocument }> {
    return readDiscoveryResponse(browser, baseUrl, async (response) => {
        expect(response.ok(), `${await response.text()}\nIf the host is not served: ${OWN_HOST_REQUIREMENT}`).toBeTruthy();

        return { headers: response.headers(), document: (await response.json()) as UcpProfileDocument };
    });
}

export async function fetchRawDiscoveryResponse(browser: Browser, baseUrl: string): Promise<{ status: number, body: string }> {
    return readDiscoveryResponse(browser, baseUrl, async response => ({ status: response.status(), body: await response.text() }));
}

async function readDiscoveryResponse<T>(browser: Browser, baseUrl: string, toResult: (response: Response) => T | Promise<T>): Promise<T> {
    const url = `${baseUrl.replace(/\/+$/, '')}/.well-known/ucp`;
    const context = await browser.newContext({ ignoreHTTPSErrors });
    try {
        const page = await context.newPage();
        const response = await page.goto(url).catch((error: unknown) => {
            throw new Error(`${url} is not reachable. ${OWN_HOST_REQUIREMENT}\n${String(error)}`);
        });
        expect(response, `no response for ${url}`).not.toBeNull();

        return await toResult(response!);
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
