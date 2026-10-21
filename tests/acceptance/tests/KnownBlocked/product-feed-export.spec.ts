import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import type { Request } from '@playwright/test';
import { AgenticCommerceFeedDetail } from '@page-objects/AgenticCommerceFeedDetail';
import { NEW_FEED_SAVES_ON_SECOND_CLICK, TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE } from '../UcpContent/knownBlockerRanges';

/** The create page's write of the new channel. */
const isChannelCreation = (request: Request) => request.method() === 'POST'
  && /\/api\/(sales-channel|_action\/sync)$/.test(new URL(request.url()).pathname);

test.describe('Saving a new feed channel before 6.7.0.0 @UcpKnownBlocked @UcpBlockedByD12', () => {
    test('a new feed channel without a return policy URL stays unsaved however often Save is clicked', async ({ InstanceMeta, AdminPage }) => {
        test.skip(!satisfies(InstanceMeta.version, NEW_FEED_SAVES_ON_SECOND_CLICK), 'D12 applies only below 6.7.0.0; tests/UcpAdmin/product-feed-export.spec.ts covers newer versions');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.openNew();

        const creations: Request[] = [];
        AdminPage.on('request', request => isChannelCreation(request) && creations.push(request));

        for (const click of [1, 2]) {
            await feed.saveButton.click();

            await expect(feed.fieldOf(feed.returnPolicyUrl).locator('.mt-field__error, .sw-field__error'), `marked after click ${click}`).toBeVisible();
            expect(creations.map(request => request.url()), `nothing sent after click ${click}`).toEqual([]);
        }
    });
});

test.describe('Template select after a cancelled change @UcpKnownBlocked @UcpBlockedByD13', () => {
    test('cancelling the template change shows the current template again before the Meteor select', async ({ InstanceMeta, TestDataService, AdminPage }) => {
        test.skip(!satisfies(InstanceMeta.version, TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE), 'D13 applies only below 6.7.0.0; tests/UcpAdmin/product-feed-export.spec.ts covers newer versions');
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        await feed.chooseTemplate('google');
        await feed.cancelTemplateChange();

        await feed.expectTemplateShown('open_ai', /OpenAI/);
    });
});
