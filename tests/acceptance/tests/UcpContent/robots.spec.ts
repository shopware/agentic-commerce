import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';

const TRACKED_LINK_ALLOW = 'Allow: /*referringSalesChannel=';

test.describe('robots.txt and tracked feed links @UcpContent', () => {
    test('robots.txt answers on every version that serves one', async ({ InstanceMeta, DefaultSalesChannel, request }) => {
        test.skip(satisfies(InstanceMeta.version, '<6.7.1.0'), 'robots.txt arrived in 6.7.1.0');

        const robotsTxt = await request.get(`${DefaultSalesChannel.url}robots.txt`);

        expect(robotsTxt.status(), await robotsTxt.text()).toBe(200);
    });

    test('robots.txt allows crawling tracked feed links exactly once', async ({ InstanceMeta, DefaultSalesChannel, request }) => {
        test.skip(satisfies(InstanceMeta.version, '<6.7.5.0'), 'robots.txt directives became extensible in 6.7.5.0');

        const robotsTxt = await request.get(`${DefaultSalesChannel.url}robots.txt`);
        const trackedLinkAllowLines = (await robotsTxt.text()).split('\n').filter(line => line.trim() === TRACKED_LINK_ALLOW);

        expect(trackedLinkAllowLines).toHaveLength(1);
    });
});
