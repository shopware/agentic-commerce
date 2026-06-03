const ApiService = Shopware.Classes.ApiService;
const { Application } = Shopware;

class UcpAdminApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'ucp') {
        super(httpClient, loginService, apiEndpoint);
    }

    getSalesChannels() {
        return this.httpClient.get('/_admin/ucp/sales-channels', { headers: this.getBasicHeaders() });
    }

    getSalesChannel(salesChannelId) {
        return this.httpClient.get(`/_admin/ucp/sales-channels/${salesChannelId}`, { headers: this.getBasicHeaders() });
    }

    getConfig(salesChannelId) {
        return this.httpClient.get(`/_admin/ucp/sales-channels/${salesChannelId}/config`, { headers: this.getBasicHeaders() });
    }

    saveConfig(salesChannelId, payload) {
        return this.httpClient.put(`/_admin/ucp/sales-channels/${salesChannelId}/config`, payload, { headers: this.getBasicHeaders() });
    }

    getProfilePreview(salesChannelId) {
        return this.httpClient.get(`/_admin/ucp/sales-channels/${salesChannelId}/profile-preview`, { headers: this.getBasicHeaders() });
    }

    getKeys(salesChannelId) {
        return this.httpClient.get(`/_admin/ucp/sales-channels/${salesChannelId}/keys`, { headers: this.getBasicHeaders() });
    }

    createKey(salesChannelId, payload = {}) {
        return this.httpClient.post(`/_admin/ucp/sales-channels/${salesChannelId}/keys`, payload, { headers: this.getBasicHeaders() });
    }

    retireKey(salesChannelId, kid) {
        return this.httpClient.post(`/_admin/ucp/sales-channels/${salesChannelId}/keys/${kid}/retire`, {}, { headers: this.getBasicHeaders() });
    }

    deleteKey(salesChannelId, kid) {
        return this.httpClient.delete(`/_admin/ucp/sales-channels/${salesChannelId}/keys/${kid}`, { headers: this.getBasicHeaders() });
    }
}

Application.addServiceProvider('ucpAdminApiService', () => {
    const initContainer = Application.getContainer('init');

    return new UcpAdminApiService(initContainer.httpClient, Shopware.Service('loginService'));
});
