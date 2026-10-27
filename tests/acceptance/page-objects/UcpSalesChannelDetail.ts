import { expect } from '@playwright/test';
import type { Locator, Page, Response } from '@playwright/test';
import type { UcpProfileDocument } from '@services/UcpTestDataService';
import { goToAdminRoute } from './adminRoute';

export interface UcpAdminMeta {
    shopwareVersion?: string
    supportsStoreApiMcp?: unknown
}

const ucpApiPath = (salesChannelId: string, suffix = '') => `/api/_admin/ucp/sales-channels/${salesChannelId}${suffix}`;

/**
 * The sales channel detail page as the plugin extends it: the Agentic Commerce tab, its Exposure
 * and Preview sub-tabs, and the page's global Save. Locators use the plugin's `data-testid` hooks.
 */
export class UcpSalesChannelDetail {
    readonly agenticCommerceTab: Locator;
    readonly unsavedBanner: Locator;
    readonly exposeSwitch: Locator;
    readonly preview: Locator;
    readonly saveButton: Locator;

    constructor(public readonly page: Page) {
        this.agenticCommerceTab = page.getByTestId('ac-ucp');
        this.unsavedBanner = page.getByTestId('ac-ucp__unsaved-banner');
        this.exposeSwitch = this.checkboxInput('ac-ucp__expose-switch');
        this.preview = page.getByTestId('ac-ucp__preview');
        this.saveButton = page.locator('.sw-sales-channel-detail__save-action');
    }

    capability(value: string): Locator {
        return this.checkboxInput(`ac-ucp__capability--${value}`);
    }

    transport(value: string): Locator {
        return this.checkboxInput(`ac-ucp__transport--${value}`);
    }

    /** The transports the tab offers, read from their hooks. */
    async offeredTransports(): Promise<string[]> {
        const hooks = await this.page.locator('[data-testid^="ac-ucp__transport--"]').all();

        return Promise.all(hooks.map(async hook => ((await hook.getAttribute('data-testid')) ?? '').replace('ac-ucp__transport--', '')));
    }

    /**
     * Shows a sub-tab of the UCP card. The legacy layout (before 6.6.2) has no sub-tabs and renders
     * the preview as a card of its own below the form; whichever of the two is rendered tells which.
     */
    async showSubTab(name: 'exposure' | 'preview'): Promise<void> {
        const subTab = this.agenticCommerceTab.locator(`[data-item-name="${name}"]`);
        await expect(subTab.or(this.preview).first()).toBeVisible();
        if (await subTab.count() > 0) {
            await subTab.click();
        }
    }

    /**
     * Opens the Agentic Commerce tab and waits for the three requests the page loads the UCP state
     * with, plus the preview request the loaded form triggers. Returns the `meta` the Admin API sent.
     */
    async openAgenticCommerceTab(salesChannelId: string): Promise<UcpAdminMeta> {
        return this.loadAgenticCommerceTab(salesChannelId, () => goToAdminRoute(this.page, `/sw/sales/channel/detail/${salesChannelId}/agentic-commerce`));
    }

    async reload(salesChannelId: string): Promise<UcpAdminMeta> {
        return this.loadAgenticCommerceTab(salesChannelId, () => this.page.reload());
    }

    /**
     * Clicks the page's Save, waits for the UCP config write, the last request of the save chain,
     * and then for the page to show the stored config again: the Administration loads it once more
     * after the write, and until then the tab may be unmounted.
     */
    async saveUcpConfig(salesChannelId: string): Promise<void> {
        let writtenAt = Number.POSITIVE_INFINITY;
        const storedConfigLoaded = this.page.waitForResponse(response => isRequest(response, 'GET', ucpApiPath(salesChannelId, '/config'))
          && response.request().timing().startTime > writtenAt);
        const configWrite = this.page.waitForResponse(response => isRequest(response, 'PUT', ucpApiPath(salesChannelId, '/config')));

        await this.saveButton.click();
        const response = await configWrite;
        expect(response.ok(), await response.text()).toBeTruthy();
        const timing = response.request().timing();
        writtenAt = timing.startTime + Math.max(timing.responseStart, 0);

        await storedConfigLoaded;
        await this.waitUntilSettled();
    }

    /** Waits for the draft preview the tab requests for a form matching `matchesDraft`, and returns it. */
    waitForDraftPreview(salesChannelId: string, matchesDraft: (draft: { enabledTransports?: string[], enabledCapabilities?: string[] }) => boolean): Promise<Response> {
        return this.page.waitForResponse(response => isRequest(response, 'POST', ucpApiPath(salesChannelId, '/profile-preview'))
          && matchesDraft(response.request().postDataJSON() as { enabledTransports?: string[], enabledCapabilities?: string[] }));
    }

    async renderedPreview(): Promise<UcpProfileDocument['ucp']> {
        await expect(this.preview).not.toBeEmpty();

        return (JSON.parse(await this.preview.innerText()) as { ucp: UcpProfileDocument['ucp'] }).ucp;
    }

    private async loadAgenticCommerceTab(salesChannelId: string, navigate: () => Promise<unknown>): Promise<UcpAdminMeta> {
        // Before 6.7.9 the save loads the UCP state once more, so a request of the page being left can
        // still answer after the navigation started; its body is gone once the navigation commits.
        const navigatedAt = Date.now();
        const loaded = (method: string, path: string) => this.page.waitForResponse(response => isRequest(response, method, path)
          && response.request().timing().startTime >= navigatedAt);
        const salesChannel = loaded('GET', ucpApiPath(salesChannelId));
        const config = loaded('GET', ucpApiPath(salesChannelId, '/config'));
        const savedPreview = loaded('GET', ucpApiPath(salesChannelId, '/profile-preview'));
        const loadedFormPreview = loaded('POST', ucpApiPath(salesChannelId, '/profile-preview'));

        await navigate();

        const salesChannelResponse = await salesChannel;
        expect(salesChannelResponse.ok(), await salesChannelResponse.text()).toBeTruthy();
        await Promise.all([config, savedPreview, loadedFormPreview]);
        await this.waitUntilSettled();

        return ((await salesChannelResponse.json()) as { meta: UcpAdminMeta }).meta;
    }

    private async waitUntilSettled(): Promise<void> {
        await expect(this.agenticCommerceTab).toBeVisible();
        await expect(this.agenticCommerceTab.locator('.sw-skeleton')).toHaveCount(0);
        // On 6.6 `mt-switch` passes the hook to its container and its field, so it matches twice.
        await expect(this.page.getByTestId('ac-ucp__expose-switch').first()).toBeVisible();
    }

    /** The hook sits on the checkbox's wrapper on every lane. */
    private checkboxInput(testId: string): Locator {
        return this.page.locator(`[data-testid="${testId}"] input[type="checkbox"]`);
    }
}

function isRequest(response: Response, method: string, path: string): boolean {
    return response.request().method() === method && new URL(response.url()).pathname.endsWith(path);
}
