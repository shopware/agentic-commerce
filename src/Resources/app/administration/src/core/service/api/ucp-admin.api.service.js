const ApiService = Shopware.Classes.ApiService;
const { Application } = Shopware;

class UcpAdminApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'ucp') {
        super(httpClient, loginService, apiEndpoint);
    }

    getSalesChannels() {
        return this.httpClient.get('/_admin/ucp/sales-channels', this.options());
    }

    getSalesChannel(salesChannelId) {
        return this.httpClient.get(this.basePath(salesChannelId), this.options());
    }

    getConfig(salesChannelId) {
        return this.httpClient.get(this.basePath(salesChannelId, '/config'), this.options());
    }

    saveConfig(salesChannelId, payload) {
        return this.httpClient.put(this.basePath(salesChannelId, '/config'), payload, this.options());
    }

    getProfilePreview(salesChannelId) {
        return this.httpClient.get(this.basePath(salesChannelId, '/profile-preview'), this.options());
    }

    // Live preview from the edited (unsaved) config — POST the form payload so
    // the rendered profile reflects pending changes (redesign §10.4).
    previewConfig(salesChannelId, payload) {
        return this.httpClient.post(this.basePath(salesChannelId, '/profile-preview'), payload, this.options());
    }

    // Applies one exposure payload to several channels; responds 200 with a
    // per-channel outcome even when some of them failed.
    bulkEnable(payload) {
        return this.httpClient.post('/_admin/ucp/onboarding/bulk-enable', payload, this.options());
    }

    // Creates one agentic feed channel per { storefrontSalesChannelId, provider }
    // pair; responds 200 with a per-pair outcome even when some of them failed.
    createFeedChannels(payload) {
        return this.httpClient.post('/_admin/ucp/onboarding/feed-channels', payload, this.options());
    }

    // The whole settings page in one read: status, the three setup steps, and
    // every offerable channel with its outstanding findings.
    getReadiness() {
        return this.httpClient.get('/_admin/ucp/onboarding/readiness', this.options());
    }

    basePath(salesChannelId, suffix = '') {
        return `/_admin/ucp/sales-channels/${encodeURIComponent(salesChannelId)}${suffix}`;
    }

    options() {
        return { headers: this.getBasicHeaders() };
    }
}

Application.addServiceProvider('ucpAdminApiService', () => {
    const initContainer = Application.getContainer('init');

    return new UcpAdminApiService(initContainer.httpClient, Shopware.Service('loginService'));
});
