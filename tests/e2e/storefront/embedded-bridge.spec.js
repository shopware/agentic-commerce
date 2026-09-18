import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';

const template = readFileSync(new URL('../../../src/Resources/views/ucp/embedded/page.html.twig', import.meta.url), 'utf8');
const script = template.match(/<script>([\s\S]*?)<\/script>/)[1];

for (const referrerPolicy of ['strict-origin-when-cross-origin', 'no-referrer']) {
    test(`cross-origin iframe bridge works with ${referrerPolicy}`, async ({ page }) => {
        const state = {
            channel: 'ucp.embedded', type: 'cart', id: 'cart-token',
            targetOrigin: null, allowedOrigins: ['https://assistant.example'],
            data: { id: 'cart-token', buyer: { email: 'buyer@example.com' } },
        };
        let navigationOrigin;
        await page.route('https://shop.example/**', async (route) => {
            navigationOrigin = route.request().headers().origin;
            await route.fulfill({
                contentType: 'text/html',
                headers: { 'Content-Security-Policy': 'frame-ancestors https://assistant.example' },
                body: `<script>${script.replace('{{ stateJson|raw }}', JSON.stringify(state))}</script>`,
            });
        });
        await page.route('https://assistant.example/**', (route) => route.fulfill({
            contentType: 'text/html',
            body: `<script>
                window.messages = [];
                window.addEventListener('message', event => window.messages.push(event.data));
                </script><iframe referrerpolicy="${referrerPolicy}" src="https://shop.example/ucp/embedded/cart/cart-token"></iframe>`,
        }));
        await page.goto('https://assistant.example/');
        expect(navigationOrigin).toBeUndefined();
        const frame = page.frames().find((candidate) => candidate.url().startsWith('https://shop.example/'));
        expect(frame).toBeDefined();

        if (referrerPolicy === 'no-referrer') {
            expect(await page.evaluate(() => window.messages)).toEqual([]);
        }
        // A same-origin sibling must not be able to bind the bridge or read buyer data.
        await frame.evaluate(() => {
            window.dispatchEvent(new MessageEvent('message', {
                origin: 'https://assistant.example', source: window,
                data: { channel: 'ucp.embedded', type: 'ucp.embedded.ping' },
            }));
            window.dispatchEvent(new MessageEvent('message', {
                origin: 'https://attacker.example', source: window.parent,
                data: { channel: 'ucp.embedded', type: 'ucp.embedded.ping' },
            }));
        });
        expect(await page.evaluate(() => window.messages.some((message) => message.type === 'ucp.embedded.pong'))).toBe(false);
        await page.evaluate(() => document.querySelector('iframe').contentWindow.postMessage({
            channel: 'ucp.embedded', type: 'ucp.embedded.ping',
        }, 'https://shop.example'));
        await expect.poll(() => page.evaluate(() => window.messages.map((message) => message.type))).toEqual([
            'ucp.embedded.ready', 'ucp.embedded.state', 'ucp.embedded.pong',
        ]);
        await page.evaluate(() => document.querySelector('iframe').contentWindow.postMessage({
            channel: 'ucp.embedded', type: 'ucp.embedded.refresh',
        }, 'https://shop.example'));
        await expect.poll(() => page.evaluate(() => window.messages.length)).toBe(4);
        expect(await page.evaluate(() => window.messages[3].payload.id)).toBe('cart-token');
    });
}
