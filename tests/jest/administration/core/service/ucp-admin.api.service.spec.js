/**
 * @jest-environment jsdom
 */

function loadService() {
    class ApiServiceStub {
        constructor(httpClient, loginService, apiEndpoint) {
            this.httpClient = httpClient;
            this.loginService = loginService;
            this.apiEndpoint = apiEndpoint;
        }

        getBasicHeaders() {
            return {};
        }
    }

    globalThis.Shopware = {
        ...globalThis.Shopware,
        Classes: { ApiService: ApiServiceStub },
        Application: { addServiceProvider: jest.fn(), getContainer: jest.fn(() => ({ httpClient: {} })) },
        Service: jest.fn(),
    };

    let UcpAdminApiService;
    jest.isolateModules(() => {
        // The module assigns the service provider as a side effect on import.
        require('Resources/core/service/api/ucp-admin.api.service.js');
        UcpAdminApiService = globalThis.Shopware.Application.addServiceProvider.mock.calls[0][1]().constructor;
    });

    return UcpAdminApiService;
}

describe('ucpAdminApiService URL encoding', () => {
    function createService() {
        const httpClient = {
            get: jest.fn(),
            post: jest.fn(),
            put: jest.fn(),
            delete: jest.fn(),
        };
        const UcpAdminApiService = loadService();

        return { service: new UcpAdminApiService(httpClient, {}), httpClient };
    }

    it('encodes salesChannelId when fetching the config', () => {
        const { service, httpClient } = createService();

        service.getConfig('sc/../id');

        expect(httpClient.get).toHaveBeenCalledWith(
            '/_admin/ucp/sales-channels/sc%2F..%2Fid/config',
            expect.any(Object),
        );
    });

    it('encodes salesChannelId when POSTing a live preview', () => {
        const { service, httpClient } = createService();

        service.previewConfig('sc with space', { active: true });

        expect(httpClient.post).toHaveBeenCalledWith(
            '/_admin/ucp/sales-channels/sc%20with%20space/profile-preview',
            { active: true },
            expect.any(Object),
        );
    });
});
