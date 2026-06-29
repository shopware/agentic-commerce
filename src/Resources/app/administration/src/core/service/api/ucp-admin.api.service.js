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

    getKeys(salesChannelId) {
        return this.httpClient.get(this.basePath(salesChannelId, '/keys'), this.options());
    }

    createKey(salesChannelId, payload = {}) {
        return this.httpClient.post(this.basePath(salesChannelId, '/keys'), payload, this.options());
    }

    retireKey(salesChannelId, kid) {
        return this.httpClient.post(this.keyPath(salesChannelId, kid, '/retire'), {}, this.options());
    }

    deleteKey(salesChannelId, kid) {
        return this.httpClient.delete(this.keyPath(salesChannelId, kid), this.options());
    }

    basePath(salesChannelId, suffix = '') {
        return `/_admin/ucp/sales-channels/${encodeURIComponent(salesChannelId)}${suffix}`;
    }

    keyPath(salesChannelId, kid, suffix = '') {
        return this.basePath(salesChannelId, `/keys/${encodeURIComponent(kid)}${suffix}`);
    }

    options() {
        return { headers: this.getBasicHeaders() };
    }
}

Application.addServiceProvider('ucpAdminApiService', () => {
    const initContainer = Application.getContainer('init');

    return new UcpAdminApiService(initContainer.httpClient, Shopware.Service('loginService'));
});
