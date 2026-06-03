import { expect, request, test } from '@playwright/test';
import {
    assertProfileTransports,
    laneConfig,
    readPublicProfile,
} from '../fixtures/shopware.js';

test.describe('UCP public profile and transports', () => {
    test('advertises lane-aware transports', async ({ request: api }) => {
        const config = laneConfig();
        const profile = await readPublicProfile(api);

        await assertProfileTransports(profile, config.expectedProfileTransports);

        const endpoints = (profile.ucp.services['dev.ucp.shopping'] || [])
            .filter((entry) => entry.transport === 'mcp')
            .map((entry) => entry.endpoint);

        if (config.lane === 'trunk') {
            expect(endpoints).toEqual([`${config.baseUrl}/ucp/mcp`]);
        } else {
            expect(endpoints).toEqual([]);
        }
    });

    test('keeps OAuth and tokenization unsupported by default', async ({ request: api }) => {
        const oauthResponse = await api.get('/.well-known/oauth-authorization-server', { failOnStatusCode: false });
        expect(oauthResponse.status()).toBe(501);

        const tokenizeResponse = await api.post('/ucp/v1/tokenize', {
            headers: {
                'idempotency-key': `playwright-tokenize-${Date.now()}`,
            },
            data: {
                type: 'tokenized',
                handler_id: 'test',
                credential: {},
            },
            failOnStatusCode: false,
        });
        expect(tokenizeResponse.status()).toBe(501);
    });

    test('initializes trunk MCP without client-provided Store API access key', async ({ request: api }) => {
        const config = laneConfig();
        test.skip(config.lane !== 'trunk', 'MCP is trunk-only for this plugin milestone.');

        const profile = await readPublicProfile(api);
        const mcpEndpoint = profile.ucp.services['dev.ucp.shopping'].find((entry) => entry.transport === 'mcp')?.endpoint;
        expect(mcpEndpoint).toBe(`${config.baseUrl}/ucp/mcp`);

        const mcpApi = await request.newContext();
        const initializeResponse = await mcpApi.post(mcpEndpoint, {
            headers: {
                'content-type': 'application/json',
            },
            data: {
                jsonrpc: '2.0',
                method: 'initialize',
                params: {
                    protocolVersion: '2025-03-26',
                    capabilities: {},
                    clientInfo: { name: 'ucp-playwright', version: '1.0.0' },
                },
                id: 201,
            },
        });

        await expect(initializeResponse, await initializeResponse.text()).toBeOK();
        expect(initializeResponse.headers()['mcp-session-id']).toEqual(expect.any(String));
        const body = await initializeResponse.json();
        expect(body.result.serverInfo.name).toContain('Shopware Store API');

        await mcpApi.dispose();
    });
});
