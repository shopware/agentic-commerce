import { expect } from '@playwright/test';
import type { Request } from '@playwright/test';
import type { AgenticCommerceFeedDetail } from '@page-objects/AgenticCommerceFeedDetail';
import type { UcpTestDataService } from '@services/UcpTestDataService';

export const RETURN_POLICY_URL = 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl';

/** A write the detail page's Save could send: REST on older lanes, sync on current ones, then the provider settings. */
const SAVE_WRITE_PATHS = ['/api/sales-channel', '/api/product-export', '/api/_action/sync', '/api/_action/system-config/batch'];
export const isSaveWrite = (request: Request) => request.method() !== 'GET'
  && SAVE_WRITE_PATHS.some(path => new URL(request.url()).pathname.includes(path));

const fieldError = (feed: AgenticCommerceFeedDetail) => feed.fieldOf(feed.returnPolicyUrl).locator('.mt-field__error, .sw-field__error');

/** Saves an opened OpenAI feed whose return policy URL is empty: the field is marked, and nothing is written. */
export async function expectSaveRefusedWithoutReturnPolicyUrl(feed: AgenticCommerceFeedDetail, testData: UcpTestDataService, salesChannelId: string): Promise<void> {
    const writes: Request[] = [];
    const recordWrite = (request: Request) => isSaveWrite(request) && writes.push(request);
    feed.page.on('request', recordWrite);

    await expect(feed.returnPolicyUrl).toHaveValue('');
    await feed.saveButton.click();

    await expect(fieldError(feed)).toBeVisible();
    feed.page.off('request', recordWrite);
    expect(writes.map(request => request.url()), 'the refused save wrote nothing').toEqual([]);
    expect(await testData.getChannelSystemConfig('SwagAgenticCommerce.openAiProductExport', salesChannelId))
        .not.toHaveProperty([RETURN_POLICY_URL]);
}

/** Saves a new feed channel without a return policy URL twice: the field stays marked, and nothing is written. */
export async function expectNewFeedStaysUnsaved(feed: AgenticCommerceFeedDetail): Promise<void> {
    await feed.openNew();

    const writes: Request[] = [];
    feed.page.on('request', request => isSaveWrite(request) && writes.push(request));

    for (const click of [1, 2]) {
        await feed.saveButton.click();

        await expect(fieldError(feed), `marked after click ${click}`).toBeVisible();
        expect(writes.map(request => request.url()), `nothing sent after click ${click}`).toEqual([]);
    }
}

/** Cancels a change from the OpenAI to the Google template: the select shows OpenAI again. */
export async function expectCancelledTemplateChangeShowsCurrent(feed: AgenticCommerceFeedDetail, testData: UcpTestDataService): Promise<void> {
    const { salesChannel } = await testData.createFeedSalesChannelWithExport('open-ai');
    await feed.open(salesChannel.id);

    await feed.chooseTemplate('google');
    await feed.cancelTemplateChange();

    await feed.expectTemplateShown('open_ai', /OpenAI/);
}
