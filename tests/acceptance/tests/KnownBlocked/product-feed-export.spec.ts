import { satisfies } from 'compare-versions';
import { test } from '@fixtures/AcceptanceTest';
import { AgenticCommerceFeedDetail } from '@page-objects/AgenticCommerceFeedDetail';
import { expectCancelledTemplateChangeShowsCurrent } from '../UcpAdmin/feedSaveSteps';
import { TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE } from '../UcpContent/knownBlockerRanges';

test.describe('Template select after a cancelled change @UcpKnownBlocked @UcpBlockedByD13', () => {
    test('cancelling the template change shows the current template again before the Meteor select', async ({ InstanceMeta, TestDataService, AdminPage }) => {
        test.skip(!satisfies(InstanceMeta.version, TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE), 'D13 applies only below 6.7.0.0; tests/UcpAdmin/product-feed-export.spec.ts covers newer versions');
        await expectCancelledTemplateChangeShowsCurrent(new AgenticCommerceFeedDetail(AdminPage), TestDataService);
    });
});
