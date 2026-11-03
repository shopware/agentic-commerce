import type { Browser, Page } from '@playwright/test';

export interface EmbeddingHostPage {
    page: Page
    frameAncestorRefusals: string[]
    receivedMessages(): Promise<unknown[]>
}

/** `hostOrigin` needs the scheme of `embeddedUrl`: a browser blocks an http frame inside an https page as mixed content. */
export async function openInEmbeddingHost(browser: Browser, hostOrigin: string, embeddedUrl: string): Promise<EmbeddingHostPage> {
    const context = await browser.newContext();
    const page = await context.newPage();
    const frameAncestorRefusals: string[] = [];
    page.on('console', (message) => {
        if (message.text().includes('frame-ancestors')) {
            frameAncestorRefusals.push(message.text());
        }
    });

    const hostUrl = `${hostOrigin}/embedding-host`;
    await page.route(hostUrl, route => route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><title>Embedding host</title>
<script>window.receivedMessages = []; window.addEventListener('message', event => window.receivedMessages.push(event.data));</script>
<iframe title="Embedded checkout" src="${embeddedUrl}"></iframe>`,
    }));
    const embeddedResponse = page.waitForResponse(embeddedUrl);
    await page.goto(hostUrl);
    await embeddedResponse;
    await page.waitForLoadState('load');

    return {
        page,
        frameAncestorRefusals,
        receivedMessages: () => page.evaluate(() => (globalThis as unknown as { receivedMessages: unknown[] }).receivedMessages),
    };
}
