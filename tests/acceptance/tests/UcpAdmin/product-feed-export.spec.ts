import { satisfies } from 'compare-versions';
import { expect, test } from '@fixtures/AcceptanceTest';
import type { Request } from '@playwright/test';
import { AgenticCommerceFeedDetail } from '@page-objects/AgenticCommerceFeedDetail';
import { readFeedTemplates } from '@services/pluginSource';
import { NEW_FEED_SAVES_ON_SECOND_CLICK, SAVE_SHOWS_NO_REQUIRED_SETTING_ERROR, TEMPLATE_SELECT_KEEPS_CANCELLED_CHOICE } from '../UcpContent/knownBlockerRanges';

const RETURN_POLICY_URL = 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl';

/** A write the detail page's Save could send: REST on older lanes, sync on current ones, then the provider settings. */
const SAVE_WRITE_PATHS = ['/api/sales-channel', '/api/product-export', '/api/_action/sync', '/api/_action/system-config/batch'];
const isSaveWrite = (request: Request) => request.method() !== 'GET'
  && SAVE_WRITE_PATHS.some(path => new URL(request.url()).pathname.includes(path));

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
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        await feed.chooseTemplate('google');
        await feed.cancelTemplateChange();

        await feed.expectTemplateShown('open_ai', /OpenAI/);
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

    test('an OpenAI feed is not saved without a return policy URL', async ({ InstanceMeta, TestDataService, AdminPage }) => {
        test.skip(satisfies(InstanceMeta.version, SAVE_SHOWS_NO_REQUIRED_SETTING_ERROR), 'known blocker D12, asserted in tests/KnownBlocked/product-feed-export.spec.ts');
        const { salesChannel } = await TestDataService.createFeedSalesChannelWithExport('open-ai');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.open(salesChannel.id);

        const requests: Request[] = [];
        const recordRequest = (request: Request) => requests.push(request);
        AdminPage.on('request', recordRequest);

        await test.step('saving without it marks the field and sends nothing', async () => {
            await expect(feed.returnPolicyUrl).toHaveValue('');
            await feed.saveButton.click();

            await expect(feed.fieldOf(feed.returnPolicyUrl).locator('.mt-field__error, .sw-field__error')).toBeVisible();
            AdminPage.off('request', recordRequest);
            expect(requests.filter(isSaveWrite).map(request => request.url()), 'the refused save wrote nothing').toEqual([]);
            expect(await TestDataService.getChannelSystemConfig('SwagAgenticCommerce.openAiProductExport', salesChannel.id))
                .not.toHaveProperty([RETURN_POLICY_URL]);
        });

        await test.step('with it, the save goes through and stores the URL', async () => {
            await feed.returnPolicyUrl.fill('shop.example/returns');
            await feed.save();

            await expect(feed.fieldOf(feed.returnPolicyUrl).locator('.mt-field__error, .sw-field__error')).toHaveCount(0);
            expect(await TestDataService.getChannelSystemConfig('SwagAgenticCommerce.openAiProductExport', salesChannel.id))
                .toHaveProperty([RETURN_POLICY_URL], expect.stringContaining('shop.example/returns'));
        });
    });

    test('a new feed channel without a return policy URL stays unsaved however often Save is clicked', async ({ InstanceMeta, AdminPage }) => {
        test.skip(satisfies(InstanceMeta.version, NEW_FEED_SAVES_ON_SECOND_CLICK), 'known blocker D12, asserted in tests/KnownBlocked/product-feed-export.spec.ts');
        const feed = new AgenticCommerceFeedDetail(AdminPage);
        await feed.openNew();

        const creations: Request[] = [];
        AdminPage.on('request', request => isSaveWrite(request) && creations.push(request));

        for (const click of [1, 2]) {
            await feed.saveButton.click();

            await expect(feed.fieldOf(feed.returnPolicyUrl).locator('.mt-field__error, .sw-field__error'), `marked after click ${click}`).toBeVisible();
            expect(creations.map(request => request.url()), `nothing sent after click ${click}`).toEqual([]);
        }
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
