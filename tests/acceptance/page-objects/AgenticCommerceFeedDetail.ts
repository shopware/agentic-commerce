import { expect } from '@playwright/test';
import type { Locator, Page, Response } from '@playwright/test';
import { FEED_SALES_CHANNEL_TYPE_ID } from '@services/UcpTestDataService';
import { goToAdminRoute } from './adminRoute';

const OPEN_AI_CONFIG_DOMAIN = 'SwagAgenticCommerce.openAiProductExport';
const GOOGLE_CONFIG_DOMAIN = 'SwagAgenticCommerce.googleProductExport';

/**
 * The detail page of a product-feed (Agentic Commerce) channel. From Shopware 6.7.10 core renders
 * most of it; locators target what both renderings share: plugin hooks, config keys and the BEM
 * classes core kept from the plugin.
 */
export class AgenticCommerceFeedDetail {
    readonly templateSelect: Locator;
    readonly templateModal: Locator;
    readonly resetTemplateButton: Locator;
    readonly returnPolicyUrl: Locator;
    readonly saveButton: Locator;

    constructor(public readonly page: Page) {
        this.templateSelect = page.getByTestId('ac-export__template-select');
        this.templateModal = page.locator('.sw-modal');
        this.resetTemplateButton = page.locator('.sw-sales-channel-detail-product-comparison__reset-action');
        this.returnPolicyUrl = page.locator(`input[name="${OPEN_AI_CONFIG_DOMAIN}.returnPolicyUrl"]`);
        this.saveButton = page.locator('.sw-sales-channel-detail__save-action');
    }

    /**
     * Opens a tab of the channel and waits until the provider settings are loaded and no loader is
     * left: before that the page validates and saves none of them, and Save behaves differently than
     * on the page a merchant sees.
     */
    async open(salesChannelId: string, view: 'base' | 'product-comparison' = 'base'): Promise<void> {
        const providerSettings = [OPEN_AI_CONFIG_DOMAIN, GOOGLE_CONFIG_DOMAIN].map(domain => this.page.waitForResponse(
            response => isSystemConfigRead(response, domain, salesChannelId),
        ));

        await goToAdminRoute(this.page, `/sw/sales/channel/detail/${salesChannelId}/${view}`);
        await Promise.all(providerSettings);
        await this.expectLoaded();
    }

    /** 6.5 renders the template select as a native `<select>`, later lanes as a Meteor select. */
    async chooseTemplate(templateName: 'open_ai' | 'google'): Promise<void> {
        await expect(this.templateSelect).toBeVisible();
        const nativeSelect = this.templateSelect.locator('select');
        if (await nativeSelect.count() > 0) {
            await nativeSelect.selectOption(templateName);

            return;
        }

        await this.templateSelect.locator('.mt-select__selection').click();
        await this.page.getByTestId(`mt-select-option--${templateName}`).click();
    }

    /** Opens the create page of a new feed channel and waits until its provider settings are rendered and no loader is left. */
    async openNew(): Promise<void> {
        await goToAdminRoute(this.page, `/sw/sales/channel/create/${FEED_SALES_CHANNEL_TYPE_ID}/base`);
        await expect(this.returnPolicyUrl).toBeVisible();
        await this.expectLoaded();
    }

    /**
     * Waits until no skeleton or loader is left. The page re-renders while it loads, and a re-render
     * resets text that a URL field has not stored yet.
     */
    async expectLoaded(): Promise<void> {
        await expect(this.page.locator('.sw-skeleton, .sw-loader')).toHaveCount(0);
    }

    /**
     * Enters the return policy URL and leaves the field: `sw-url-field` stores its text only on blur,
     * and Save validates what is stored, not what is typed.
     */
    async fillReturnPolicyUrl(url: string): Promise<void> {
        await this.returnPolicyUrl.fill(url);
        await this.returnPolicyUrl.blur();
        await expect(this.returnPolicyUrl).toHaveValue(url);
    }

    /** 6.5 and 6.6 show the template as the native `<select>` value, later lanes as the Meteor select's label. */
    async expectTemplateShown(templateName: 'open_ai' | 'google', label: RegExp): Promise<void> {
        const nativeSelect = this.templateSelect.locator('select');
        if (await nativeSelect.count() > 0) {
            await expect(nativeSelect).toHaveValue(templateName);

            return;
        }

        await expect(this.templateSelect.locator('.mt-select-selection-list__input')).toHaveValue(label);
    }

    /** The template modal's primary action applies the chosen template. */
    async confirmTemplateChange(): Promise<void> {
        await expect(this.templateModal).toBeVisible();
        await this.templateModal.locator('.mt-button--primary, .sw-button--primary').click();
        await expect(this.templateModal).toBeHidden();
    }

    /** The template modal's secondary action keeps the current template. On 6.5 it is a footer button without a variant. */
    async cancelTemplateChange(): Promise<void> {
        await expect(this.templateModal).toBeVisible();
        await this.templateModal.locator('.mt-button--secondary, .sw-button--secondary, .sw-modal__footer .sw-button:not(.sw-button--primary)').click();
        await expect(this.templateModal).toBeHidden();
    }

    trackingCode(field: 'affiliate-code' | 'campaign-code'): Locator {
        return this.page.locator(`input[data-testid="ac-tracking__${field}"], [data-testid="ac-tracking__${field}"] input`);
    }

    /** The innermost field wrapper around an input, which carries the field's error. */
    fieldOf(input: Locator): Locator {
        return this.page.locator('.mt-url-field, .mt-field, .sw-field').filter({ has: input }).last();
    }

    /**
     * Clicks Save and waits for the provider settings write, which the page sends after the sales
     * channel and its export are stored.
     */
    async save(): Promise<void> {
        const settingsWrite = this.page.waitForResponse(response => response.request().method() === 'POST'
          && new URL(response.url()).pathname.endsWith('/api/_action/system-config/batch'));
        await this.saveButton.click();
        const response = await settingsWrite;
        expect(response.ok(), await response.text()).toBeTruthy();
    }
}

function isSystemConfigRead(response: Response, domain: string, salesChannelId: string): boolean {
    const url = new URL(response.url());

    return response.request().method() === 'GET'
      && url.pathname.endsWith('/api/_action/system-config')
      && url.searchParams.get('domain') === domain
      && url.searchParams.get('salesChannelId') === salesChannelId;
}
