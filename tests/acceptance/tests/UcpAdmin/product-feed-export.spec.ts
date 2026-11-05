import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import { AgenticCommerceFeedDetail } from '@page-objects/AgenticCommerceFeedDetail';
import { readFeedTemplates } from '@services/pluginSource';
import { TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE } from '../UcpContent/knownBlockerRanges';
import type { Request } from '@playwright/test';
import { RETURN_POLICY_URL, expectCancelledTemplateChangeShowsCurrent, expectNewFeedStaysUnsaved, expectSaveRefusedWithoutReturnPolicyUrl, isSaveWrite } from './feedSaveSteps';

test.describe('Product feed export settings @UcpAdmin', () => {
    test('switching the export template asks first, then stores the template and aligns the file', async ({ TestDataService, AdminPage }) => {
        const { salesChannel, productExport } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        const google = (await readFeedTemplates()).find(template => template.providerName === 'google')!;
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        await feed.chooseTemplate('google');
        await feed.confirmTemplateChange();
        await feed.save();

        const [stored] = (await TestDataService.getSalesChannelReadback(salesChannel.id)).productExports;
        expect(stored).toMatchObject({
            provider: 'google',
            fileFormat: 'xml',
            fileName: productExport.fileName.replace(/\.jsonl$/, '.xml'),
            headerTemplate: google.headerTemplate,
            bodyTemplate: google.bodyTemplate,
            footerTemplate: google.footerTemplate,
        });
    });

    test('cancelling the template change keeps the current template and file', async ({ TestDataService, AdminPage }) => {
        const { salesChannel, productExport } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        await TestDataService.setChannelSystemConfig(salesChannel.id, { [RETURN_POLICY_URL]: 'https://shop.example/returns' });
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        await feed.chooseTemplate('google');
        await feed.cancelTemplateChange();
        await feed.save();

        const [stored] = (await TestDataService.getSalesChannelReadback(salesChannel.id)).productExports;
        expect(stored).toMatchObject({
            provider: 'open-ai',
            fileFormat: 'jsonl',
            fileName: productExport.fileName,
            bodyTemplate: productExport.bodyTemplate,
        });
    });

    test('cancelling the template change shows the current template again', async ({ InstanceMeta, TestDataService, AdminPage }) => {
        test.skip(satisfies(InstanceMeta.version, TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE), 'known blocker D13, asserted in tests/KnownBlocked/product-feed-export.spec.ts');
        await expectCancelledTemplateChangeShowsCurrent(new AgenticCommerceFeedDetail(AdminPage), TestDataService);
    });

    test('resetting the export template restores the provider\'s default', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai', { bodyTemplate: '{{ product.productNumber }}' });
        await TestDataService.setChannelSystemConfig(salesChannel.id, { [RETURN_POLICY_URL]: 'https://shop.example/returns' });
        const openAi = (await readFeedTemplates()).find(template => template.providerName === 'open-ai')!;
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id, 'product-comparison');

        await feed.resetTemplateButton.click();
        await feed.save();

        const [stored] = (await TestDataService.getSalesChannelReadback(salesChannel.id)).productExports;
        expect(stored.bodyTemplate).toBe(openAi.bodyTemplate);
    });

    test('an OpenAI feed is not saved without a return policy URL', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        await test.step('saving without it marks the field and sends nothing', async () => {
            await expectSaveRefusedWithoutReturnPolicyUrl(feed, TestDataService, salesChannel.id);
        });

        await test.step('with it, the save goes through and stores the URL', async () => {
            await feed.fillReturnPolicyUrl('shop.example/returns');
            await feed.save();

            await expect(feed.fieldOf(feed.returnPolicyUrl).locator('.mt-field__error, .sw-field__error')).toHaveCount(0);
            expect(await TestDataService.getChannelSystemConfig('SwagAgenticCommerce.openAiProductExport', salesChannel.id))
                .toHaveProperty([RETURN_POLICY_URL], expect.stringContaining('shop.example/returns'));
        });
    });

    test('clearing the stored return policy URL refuses the save and keeps the stored URL', async ({ TestDataService, AdminPage }) => {
        const storedUrl = 'https://shop.example/returns';
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        await TestDataService.setChannelSystemConfig(salesChannel.id, { [RETURN_POLICY_URL]: storedUrl });
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        await feed.fillReturnPolicyUrl('');

        await expectSaveRefusedWithoutReturnPolicyUrl(feed, TestDataService, salesChannel.id, storedUrl);
    });

    test('saving from another tab without a return policy URL says where the missing setting is', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id, 'product-comparison');
        const writes: Request[] = [];
        AdminPage.on('request', request => isSaveWrite(request) && writes.push(request));

        await feed.saveButton.click();

        await expect(AdminPage.getByText('A required feed setting is missing. Fill in the marked field on the General tab, then save again.')).toBeVisible();
        expect(writes.map(request => request.url()), 'the refused save wrote nothing').toEqual([]);
    });

    test('the General tab shows the missing return policy URL as marked after saving from another tab', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id, 'product-comparison');
        await feed.saveButton.click();

        await feed.switchToGeneralTab();

        await expect(feed.fieldOf(feed.returnPolicyUrl).locator('.mt-field__error, .sw-field__error')).toBeVisible();
    });

    test('a new feed channel without a return policy URL stays unsaved however often Save is clicked', async ({ AdminPage }) => {
        await expectNewFeedStaysUnsaved(new AgenticCommerceFeedDetail(AdminPage));
    });

    test('saving a new feed channel without a return policy URL names no tab, as the create page has none', async ({ AdminPage }) => {
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.openNew();

        await feed.saveButton.click();

        await expect(AdminPage.getByText('A required feed setting is missing. Fill in the marked field, then save again.')).toBeVisible();
    });

    test('tracking codes entered for a feed are stored in the channel configuration', async ({ TestDataService, AdminPage }) => {
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        await TestDataService.setChannelSystemConfig(salesChannel.id, { [RETURN_POLICY_URL]: 'https://shop.example/returns' });
        const affiliateCode = `aff-${salesChannel.id.slice(0, 8)}`;
        const campaignCode = `camp-${salesChannel.id.slice(0, 8)}`;
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        await feed.trackingCode('affiliate-code').fill(affiliateCode);
        await feed.trackingCode('campaign-code').fill(campaignCode);
        await feed.save();

        const { configuration } = await TestDataService.getSalesChannelReadback(salesChannel.id);
        expect(configuration).toMatchObject({ affiliateCode, campaignCode });
    });
});
