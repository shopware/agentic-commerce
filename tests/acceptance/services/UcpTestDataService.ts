import { expect } from '@playwright/test';
import { TestDataService } from '@shopware-ag/acceptance-test-suite';
import type { DataServiceOptions, FixtureTypes, SalesChannel } from '@shopware-ag/acceptance-test-suite';
import type { UcpConsole } from './UcpConsole';

/** Core's headless (API) sales channel type, `Defaults::SALES_CHANNEL_TYPE_API`. */
export const HEADLESS_SALES_CHANNEL_TYPE_ID = 'f183ee5650cf4bdb8a774337575067a6';
/** The plugin's product-feed type, `SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE`. */
export const FEED_SALES_CHANNEL_TYPE_ID = '5e29f9890c4d4d519a1c7f9d5c24b7c1';

export const UCP_SALES_CHANNEL_TYPE_NOT_SUPPORTED = 'SWAG_AGENTIC_COMMERCE__UCP_SALES_CHANNEL_TYPE_NOT_SUPPORTED';

/** Mirrors `UcpConfig::toArray()`. */
export interface UcpConfigPayload {
    active: boolean
    profileDomain: string | null
    enabledCapabilities: string[]
    enabledTransports: string[]
    continueUrlTemplate: string | null
    platformAllowlist: string[]
    remoteProfileAllowlist: string[]
    agentAllowlist: string[]
    embeddedAllowedOrigins: string[]
    embeddedFrameAncestors: string[]
    discoveryBudget: number
    catalogResultLimit: number
    webhookUrlOverride: string | null
    signaturePolicy: string
    idempotencyRequired: boolean
}

/** `UcpConfig::fromArray([])`: what a sales channel has before anyone touches its UCP tab. */
export const DEFAULT_UCP_CONFIG: UcpConfigPayload = {
    active: false,
    profileDomain: null,
    enabledCapabilities: [],
    enabledTransports: ['rest'],
    continueUrlTemplate: null,
    platformAllowlist: [],
    remoteProfileAllowlist: [],
    agentAllowlist: [],
    embeddedAllowedOrigins: [],
    embeddedFrameAncestors: [],
    discoveryBudget: 10,
    catalogResultLimit: 50,
    webhookUrlOverride: null,
    signaturePolicy: 'strict',
    idempotencyRequired: true,
};

export const ALL_UCP_CAPABILITIES = ['catalog', 'cart', 'discount', 'checkout', 'order'];

export interface UcpSalesChannel {
    salesChannel: SalesChannel
    /** Path-prefixed base URL of the channel's only domain, trailing slash included. */
    url: string
}

export interface UcpSalesChannelListEntry {
    id: string
    name: string | null
    typeId: string
    transactional: boolean
    domains: { id: string, url: string }[]
    ucp: Record<string, unknown>
}

interface ApiErrorBody {
    errors?: { code?: string, detail?: string }[]
}

/** The `/.well-known/ucp` document, as far as the specs read it. */
export interface UcpProfileDocument {
    ucp: {
        version: string
        services: Record<string, { transport: string, endpoint: string }[]>
        capabilities: Record<string, { version: string, extends?: string[] | null }[]>
    }
    signing_keys: { kid: string, alg: string, crv: string }[]
}

export interface UcpDataServiceOptions extends DataServiceOptions {
    appUrl: string
    baseConfig: FixtureTypes['SalesChannelBaseConfig']
    /** Host the shop fetches agent profiles from, allowlisted on every channel this service activates. */
    agentHost: string
    console?: UcpConsole
}

export class UcpTestDataService extends TestDataService {
    public readonly namePrefix: string = 'Test-';
    public readonly nameSuffix: string = '';

    private readonly appUrl: string;
    private readonly baseConfig: FixtureTypes['SalesChannelBaseConfig'];
    private readonly agentHost: string;
    private readonly console?: UcpConsole;

    private readonly createdSalesChannelIds: string[] = [];
    private readonly ucpConfigWrittenSalesChannelIds = new Set<string>();

    constructor(AdminApiClient: FixtureTypes['AdminApiContext'], IdProvider: FixtureTypes['IdProvider'], options: UcpDataServiceOptions) {
        super(AdminApiClient, IdProvider, options);

        this.appUrl = options.appUrl;
        this.baseConfig = options.baseConfig;
        this.agentHost = options.agentHost;
        this.console = options.console;
    }

    wellKnownUrl(baseUrl: string): string {
        return `${baseUrl.replace(/\/+$/, '')}/.well-known/ucp`;
    }

    async createStorefrontSalesChannel(overrides: Partial<SalesChannel> = {}): Promise<UcpSalesChannel> {
        return this.createUcpSalesChannel(this.baseConfig.storefrontTypeId, overrides);
    }

    async createHeadlessSalesChannel(overrides: Partial<SalesChannel> = {}): Promise<UcpSalesChannel> {
        return this.createUcpSalesChannel(HEADLESS_SALES_CHANNEL_TYPE_ID, overrides);
    }

    async createFeedSalesChannel(overrides: Partial<SalesChannel> = {}): Promise<UcpSalesChannel> {
        return this.createUcpSalesChannel(FEED_SALES_CHANNEL_TYPE_ID, overrides);
    }

    async createUcpSalesChannel(typeId: string, overrides: Partial<SalesChannel> = {}): Promise<UcpSalesChannel> {
        const { id, uuid } = this.IdProvider.getIdPair();
        const { uuid: rootCategoryId } = this.IdProvider.getIdPair();
        const { uuid: customerGroupId } = this.IdProvider.getIdPair();
        const { uuid: domainId } = this.IdProvider.getIdPair();
        const url = `${this.appUrl}test-${uuid}/`;

        const response = await this.AdminApiClient.post('./_action/sync', {
            data: {
                'write-sales-channel': {
                    entity: 'sales_channel',
                    action: 'upsert',
                    payload: [{
                        id: uuid,
                        name: `${this.namePrefix}UCP-${id}${this.nameSuffix}`,
                        typeId,
                        languageId: this.baseConfig.currentLanguageId,
                        currencyId: this.baseConfig.currentCurrencyId,
                        paymentMethodId: this.baseConfig.invoicePaymentMethodId,
                        shippingMethodId: this.baseConfig.defaultShippingMethod,
                        countryId: this.baseConfig.currentCountryId,
                        accessKey: `SWSC${uuid}`,
                        homeEnabled: true,
                        navigationCategory: {
                            id: rootCategoryId,
                            name: `${this.namePrefix}UCP-${id}${this.nameSuffix}`,
                            displayNestedProducts: true,
                            type: 'page',
                            productAssignmentType: 'product',
                        },
                        domains: [{
                            id: domainId,
                            url,
                            languageId: this.baseConfig.currentLanguageId,
                            snippetSetId: this.baseConfig.currentSnippetSetId,
                            currencyId: this.baseConfig.currentCurrencyId,
                        }],
                        customerGroup: {
                            id: customerGroupId,
                            name: `${this.namePrefix}UCP-${id}${this.nameSuffix}`,
                        },
                        languages: [{ id: this.baseConfig.currentLanguageId }],
                        countries: [{ id: this.baseConfig.currentCountryId }],
                        shippingMethods: [{ id: this.baseConfig.defaultShippingMethod }],
                        paymentMethods: [{ id: this.baseConfig.invoicePaymentMethodId }],
                        currencies: [{ id: this.baseConfig.currentCurrencyId }],
                        ...overrides,
                    }],
                },
            },
        });
        expect(response.ok(), await response.text()).toBeTruthy();

        this.createdSalesChannelIds.push(uuid);
        this.addCreatedRecord('category', rootCategoryId);
        this.addCreatedRecord('customer_group', customerGroupId);

        const salesChannelResponse = await this.AdminApiClient.get(`./sales-channel/${uuid}`);
        expect(salesChannelResponse.ok(), await salesChannelResponse.text()).toBeTruthy();
        const { data: salesChannel } = (await salesChannelResponse.json()) as { data: SalesChannel };

        return { salesChannel, url };
    }

    async listUcpSalesChannels(): Promise<UcpSalesChannelListEntry[]> {
        const response = await this.AdminApiClient.get('./_admin/ucp/sales-channels');
        expect(response.ok(), await response.text()).toBeTruthy();

        return ((await response.json()) as { data: UcpSalesChannelListEntry[] }).data;
    }

    async getUcpConfig(salesChannelId: string): Promise<UcpConfigPayload> {
        const response = await this.AdminApiClient.get(`./_admin/ucp/sales-channels/${salesChannelId}/config`);
        expect(response.ok(), await response.text()).toBeTruthy();

        return ((await response.json()) as { data: UcpConfigPayload }).data;
    }

    /**
     * The profile the shop would serve for this channel, resolved by id. `/.well-known/ucp` under
     * a path-prefixed domain cannot give this today (known blocker D8).
     */
    async getProfilePreview(salesChannelId: string): Promise<UcpProfileDocument['ucp']> {
        const response = await this.AdminApiClient.get(`./_admin/ucp/sales-channels/${salesChannelId}/profile-preview`);
        expect(response.ok(), await response.text()).toBeTruthy();

        return ((await response.json()) as { data: { ucp: UcpProfileDocument['ucp'] } }).data.ucp;
    }

    /**
     * Raw config write. The route merges the payload over the stored row, so a partial payload is
     * fine. Returns the response unasserted, for specs that expect a refusal.
     */
    async saveUcpConfig(salesChannelId: string, payload: Partial<UcpConfigPayload>) {
        this.ucpConfigWrittenSalesChannelIds.add(salesChannelId);

        return this.AdminApiClient.fetch(`./_admin/ucp/sales-channels/${salesChannelId}/config`, { method: 'PUT', data: payload });
    }

    /**
     * Turns UCP on with every capability, the REST transport and the agent host allowlisted, so
     * the worker's agent profile passes the SDK's per-channel host checks.
     */
    async activateUcp(salesChannelId: string, overrides: Partial<UcpConfigPayload> = {}): Promise<UcpConfigPayload> {
        const response = await this.saveUcpConfig(salesChannelId, {
            active: true,
            enabledCapabilities: ALL_UCP_CAPABILITIES,
            enabledTransports: ['rest'],
            platformAllowlist: [this.agentHost],
            remoteProfileAllowlist: [this.agentHost],
            agentAllowlist: [this.agentHost],
            ...overrides,
        });
        expect(response.ok(), await response.text()).toBeTruthy();

        return ((await response.json()) as { data: UcpConfigPayload }).data;
    }

    /** The error code of a refused config write, or null when the response carries none. */
    static async refusalCode(response: { json(): Promise<unknown> }): Promise<string | null> {
        const body = (await response.json()) as ApiErrorBody;

        return body.errors?.[0]?.code ?? null;
    }

    /**
     * Resets the UCP rows this service wrote on surviving channels, drops the signing keys of the
     * channels it created when a console is reachable, and deletes those channels. The ATS
     * registry then removes their categories and customer groups.
     */
    async cleanUpUcpEntities(): Promise<void> {
        if (!this.shouldCleanUp) {
            return;
        }

        for (const salesChannelId of this.ucpConfigWrittenSalesChannelIds) {
            if (this.createdSalesChannelIds.includes(salesChannelId)) {
                continue;
            }
            const response = await this.AdminApiClient.fetch(`./_admin/ucp/sales-channels/${salesChannelId}/config`, {
                method: 'PUT',
                data: DEFAULT_UCP_CONFIG,
            });
            expect(response.ok(), await response.text()).toBeTruthy();
        }

        if (this.console?.isAvailable()) {
            for (const salesChannelId of this.createdSalesChannelIds) {
                for (const key of this.console.listSigningKeys(salesChannelId)) {
                    this.console.deleteSigningKey(salesChannelId, key.kid);
                }
            }
        }

        if (this.createdSalesChannelIds.length > 0) {
            const response = await this.AdminApiClient.post('./_action/sync', {
                data: {
                    'delete-sales-channel': {
                        entity: 'sales_channel',
                        action: 'delete',
                        payload: this.createdSalesChannelIds.map(id => ({ id })),
                    },
                },
            });
            expect(response.ok(), await response.text()).toBeTruthy();
        }
    }
}
