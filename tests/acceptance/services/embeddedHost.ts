import type { Browser, LaunchOptions, Page } from '@playwright/test';

// The embedding hosts are served by page.route(), which Chromium treats as public, and a public page
// may not frame the private APP_URL host. In production both the host and the shop are public.
export const EMBEDDING_PRIVATE_SHOP_LAUNCH_OPTIONS: LaunchOptions = {
    args: ['--disable-features=LocalNetworkAccessChecks'],
};

export interface EmbeddingHostPage {
    page: Page
    frameAncestorRefusals: string[]
    receivedMessages(): Promise<unknown[]>
    postFromHost(message: unknown): Promise<void>
    postFromSiblingFrame(message: unknown): Promise<void>
}

interface MessageTarget {
    postMessage(message: unknown, targetOrigin: string): void
}

/** `hostOrigin` needs the scheme of `embeddedUrl`: a browser blocks an http frame inside an https page as mixed content. */
export async function openInEmbeddingHost(browser: Browser, hostOrigin: string, embeddedUrl: string, { withSiblingFrame = false } = {}): Promise<EmbeddingHostPage> {
    const context = await browser.newContext();
    const page = await context.newPage();
    const frameAncestorRefusals: string[] = [];
    page.on('console', (message) => {
        if (message.text().includes('frame-ancestors')) {
            frameAncestorRefusals.push(message.text());
        }
    });

    const hostUrl = `${hostOrigin}/embedding-host`;
    const siblingUrl = `${hostOrigin}/sibling-frame`;
    await page.route(siblingUrl, route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Sibling frame</title>' }));
    await page.route(hostUrl, route => route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><title>Embedding host</title>
<script>window.receivedMessages = []; window.addEventListener('message', event => window.receivedMessages.push(event.data));</script>
<iframe title="Embedded checkout" src="${embeddedUrl}"></iframe>
${withSiblingFrame ? `<iframe title="Sibling frame" src="${siblingUrl}"></iframe>` : ''}`,
    }));
    const embeddedResponse = page.waitForResponse(embeddedUrl);
    await page.goto(hostUrl);
    await embeddedResponse;
    await page.waitForLoadState('load');

    return {
        page,
        frameAncestorRefusals,
        receivedMessages: () => page.evaluate(() => (globalThis as unknown as { receivedMessages: unknown[] }).receivedMessages),
        postFromHost: message => page.locator('iframe[title="Embedded checkout"]').evaluate((iframe, hostMessage) => {
            (iframe as unknown as { contentWindow: MessageTarget }).contentWindow.postMessage(hostMessage, '*');
        }, message),
        postFromSiblingFrame: async (message) => {
            const siblingFrame = page.frames().find(frame => frame.url() === siblingUrl);
            if (siblingFrame === undefined) {
                throw new Error('The embedding host was opened without withSiblingFrame.');
            }
            await siblingFrame.evaluate((siblingMessage) => {
                (globalThis as unknown as { parent: { frames: MessageTarget[] } }).parent.frames[0].postMessage(siblingMessage, '*');
            }, message);
        },
    };
}
