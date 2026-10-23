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
    readonly readOnlyBanner: Locator;
    readonly exposeSwitch: Locator;
    readonly preview: Locator;
    readonly saveButton: Locator;

    constructor(public readonly page: Page) {
        this.agenticCommerceTab = page.getByTestId('ac-ucp');
        this.unsavedBanner = page.getByTestId('ac-ucp__unsaved-banner');
        this.readOnlyBanner = page.getByTestId('ac-ucp__read-only-banner');
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
     * Opens the Agentic Commerce tab and waits for the requests that load its saved UCP state.
     * Returns the `meta` the Admin API sent.
     */
    async openAgenticCommerceTab(salesChannelId: string): Promise<UcpAdminMeta> {
        return this.loadAgenticCommerceTab(salesChannelId, () => goToAdminRoute(this.page, `/sw/sales/channel/detail/${salesChannelId}/agentic-commerce`));
    }

    async reload(salesChannelId: string): Promise<UcpAdminMeta> {
        return this.loadAgenticCommerceTab(salesChannelId, () => this.page.reload());
    }

    /**
     * Clicks the page's Save and waits for the UCP-only config write and refreshed saved preview.
     * The dedicated save path does not reload the sales channel or its configuration.
     */
    async saveUcpConfig(salesChannelId: string): Promise<Response> {
        let writtenAt = Number.POSITIVE_INFINITY;
        const savedPreviewLoaded = this.page.waitForResponse(response => isRequest(response, 'GET', ucpApiPath(salesChannelId, '/profile-preview'))
          && response.request().timing().startTime > writtenAt);
        const configWrite = this.page.waitForResponse(response => isRequest(response, 'PUT', ucpApiPath(salesChannelId, '/config')));

        await this.saveButton.click();
        const response = await configWrite;
        expect(response.status(), response.status() === 200 ? '' : await response.text()).toBe(200);
        const timing = response.request().timing();
        writtenAt = timing.startTime + Math.max(timing.responseStart, 0);

        await savedPreviewLoaded;
        await this.waitUntilSettled();

        return response;
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
        const salesChannel = this.page.waitForResponse(response => isRequest(response, 'GET', ucpApiPath(salesChannelId)));
        const config = this.page.waitForResponse(response => isRequest(response, 'GET', ucpApiPath(salesChannelId, '/config')));
        const savedPreview = this.page.waitForResponse(response => isRequest(response, 'GET', ucpApiPath(salesChannelId, '/profile-preview')));

        await navigate();

        const salesChannelResponse = await salesChannel;
        expect(salesChannelResponse.ok(), await salesChannelResponse.text()).toBeTruthy();
        await Promise.all([config, savedPreview]);
        await this.waitUntilSettled();

        return ((await salesChannelResponse.json()) as { meta: UcpAdminMeta }).meta;
    }

    private async waitUntilSettled(): Promise<void> {
        await expect(this.agenticCommerceTab).toBeVisible();
        await expect(this.agenticCommerceTab.locator('.sw-skeleton')).toHaveCount(0);
        await expect(this.exposeSwitch).toBeVisible();
    }

    /** The hook sits on the checkbox's wrapper on every lane. */
    private checkboxInput(testId: string): Locator {
        return this.page.locator(`[data-testid="${testId}"] input[type="checkbox"]`);
    }
}

function isRequest(response: Response, method: string, path: string): boolean {
    return response.request().method() === method && new URL(response.url()).pathname.endsWith(path);
}
