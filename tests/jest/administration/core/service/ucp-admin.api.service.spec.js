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

    it('encodes kid and salesChannelId when retiring a key', () => {
        const { service, httpClient } = createService();

        service.retireKey('sc/../id', 'kid with space/slash');

        expect(httpClient.post).toHaveBeenCalledWith(
            '/_admin/ucp/sales-channels/sc%2F..%2Fid/keys/kid%20with%20space%2Fslash/retire',
            {},
            expect.any(Object),
        );
    });

    it('encodes kid and salesChannelId when deleting a key', () => {
        const { service, httpClient } = createService();

        service.deleteKey('sc-id', '../etc/passwd');

        expect(httpClient.delete).toHaveBeenCalledWith(
            '/_admin/ucp/sales-channels/sc-id/keys/..%2Fetc%2Fpasswd',
            expect.any(Object),
        );
    });
});
