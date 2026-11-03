import { satisfies } from 'compare-versions';
import { test } from '@fixtures/AcceptanceTest';
import { AgenticCommerceFeedDetail } from '@page-objects/AgenticCommerceFeedDetail';
import { expectCancelledTemplateChangeShowsCurrent, expectNewFeedStaysUnsaved, expectSaveRefusedWithoutReturnPolicyUrl } from '../UcpAdmin/feedSaveSteps';
import { NEW_FEED_SAVES_ON_SECOND_CLICK, SAVE_SHOWS_NO_REQUIRED_SETTING_ERROR, TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE } from '../UcpContent/knownBlockerRanges';

test.describe('Saving a feed channel with an empty required setting before 6.6.0.0 @UcpKnownBlocked @UcpBlockedByD12', () => {
    test('an OpenAI feed is not saved without a return policy URL, and the first Save marks the field', async ({ InstanceMeta, TestDataService, AdminPage }) => {
        test.skip(!satisfies(InstanceMeta.version, SAVE_SHOWS_NO_REQUIRED_SETTING_ERROR), 'D12 applies to a saved channel only below 6.6.0.0; tests/UcpAdmin/product-feed-export.spec.ts covers newer versions');
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        await expectSaveRefusedWithoutReturnPolicyUrl(feed, TestDataService, salesChannel.id);
    });
});

test.describe('Saving a new feed channel before 6.7.0.0 @UcpKnownBlocked @UcpBlockedByD12', () => {
    test('a new feed channel without a return policy URL stays unsaved however often Save is clicked', async ({ InstanceMeta, AdminPage }) => {
        test.skip(!satisfies(InstanceMeta.version, NEW_FEED_SAVES_ON_SECOND_CLICK), 'D12 applies to a new channel only below 6.7.0.0; tests/UcpAdmin/product-feed-export.spec.ts covers newer versions');
        await expectNewFeedStaysUnsaved(new AgenticCommerceFeedDetail(AdminPage));
    });
});

test.describe('Template select after a cancelled change @UcpKnownBlocked @UcpBlockedByD13', () => {
    test('cancelling the template change shows the current template again before the Meteor select', async ({ InstanceMeta, TestDataService, AdminPage }) => {
        test.skip(!satisfies(InstanceMeta.version, TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE), 'D13 applies only below 6.7.0.0; tests/UcpAdmin/product-feed-export.spec.ts covers newer versions');
        await expectCancelledTemplateChangeShowsCurrent(new AgenticCommerceFeedDetail(AdminPage), TestDataService);
    });
});
