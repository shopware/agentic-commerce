import { expect, request, test } from '@playwright/test';
import {
    laneConfig,
    readPublicProfile,
} from '../fixtures/shopware.js';

const ALWAYS_ON_TRANSPORTS = ['a2a', 'embedded', 'rest'];
const KNOWN_TRANSPORTS = ['a2a', 'embedded', 'mcp', 'rest'];

function shoppingTransports(profile) {
    const profileRoot = profile.ucp || profile;

    return profileRoot.services?.['dev.ucp.shopping'] || [];
}

async function initializeMcpSession(mcpApi, mcpEndpoint) {
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
    const sessionId = initializeResponse.headers()['mcp-session-id'];
    expect(sessionId).toEqual(expect.any(String));

    await mcpApi.post(mcpEndpoint, {
        headers: {
            'content-type': 'application/json',
            'Mcp-Session-Id': sessionId,
        },
        data: {
            jsonrpc: '2.0',
            method: 'notifications/initialized',
            params: {},
        },
    });

    return { initializeResponse, sessionId };
}

test.describe('UCP public profile and transports', () => {
    // This is a public-profile suite by design: it reads /.well-known/ucp only.
    // MCP availability depends on the running build (the core Store API MCP
    // endpoint ships with shopware/shopware#17228). Rather than re-derive that
    // through the admin API, we let the profile's own MCP presence gate the
    // MCP-specific checks. bin/ci-smoke.sh already asserts the strict
    // "supported <=> advertised" invariant server-side in the same job.
    test('advertises lane-aware transports', async ({ request: api }) => {
        const config = laneConfig();
        const profile = await readPublicProfile(api);
        const profileRoot = profile.ucp || profile;
        const services = shoppingTransports(profile);
        const transports = [...new Set(services.map((entry) => entry.transport))].sort();

        // REST/A2A/embedded are advertised on every lane; MCP is optional.
        expect(transports).toEqual(expect.arrayContaining(ALWAYS_ON_TRANSPORTS));
        expect(transports.every((transport) => KNOWN_TRANSPORTS.includes(transport))).toBe(true);
        expect(profileRoot.payment_handlers || {}).toEqual({});

        // When MCP is advertised it must resolve to the access-key-free proxy.
        const mcpEndpoints = services
            .filter((entry) => entry.transport === 'mcp')
            .map((entry) => entry.endpoint);

        if (mcpEndpoints.length > 0) {
            expect(mcpEndpoints).toEqual([`${config.baseUrl}/ucp/mcp`]);
        }
    });

    test('keeps OAuth and tokenization unsupported by default', async ({ request: api }) => {
        const oauthResponse = await api.get('/.well-known/oauth-authorization-server', { failOnStatusCode: false });
        expect(oauthResponse.status()).toBe(501);

        const tokenizeResponse = await api.post('/ucp/v1/tokenize', {
            headers: {
                'idempotency-key': `playwright-tokenize-${Date.now()}`,
                'ucp-agent': `platform; profile="${new URL('/.well-known/ucp', laneConfig().baseUrl).toString()}"`,
            },
            data: {
                credential: { type: 'card' },
                binding: { checkout_id: 'playwright-tokenization-probe' },
            },
            failOnStatusCode: false,
        });
        expect(tokenizeResponse.status()).toBe(501);
    });

    test('initializes MCP without client-provided Store API access key', async ({ request: api }) => {
        const config = laneConfig();
        const profile = await readPublicProfile(api);
        const mcpEndpoint = shoppingTransports(profile).find((entry) => entry.transport === 'mcp')?.endpoint;

        // Only the build that actually exposes the Store API MCP endpoint advertises
        // the transport; until then (e.g. trunk before shopware/shopware#17228) this
        // is correctly absent and the end-to-end init check is skipped.
        test.skip(!mcpEndpoint, 'Store API MCP endpoint not exposed by this build (trunk MCP lands with shopware/shopware#17228).');

        expect(mcpEndpoint).toBe(`${config.baseUrl}/ucp/mcp`);

        const mcpApi = await request.newContext();
        const { initializeResponse } = await initializeMcpSession(mcpApi, mcpEndpoint);

        const body = await initializeResponse.json();
        expect(body.result.serverInfo.name).toContain('Shopware Store API');

        await mcpApi.dispose();
    });

    test('exposes object payload schemas for MCP write tools', async ({ request: api }) => {
        // Object payload schemas (#[Schema]) and -32602 invalid-input errors need
        // mcp/sdk ^0.6; shopware trunk pins ^0.5 (via symfony/mcp-bundle), so the
        // write tools currently take a JSON-string payload. Re-enable this once the
        // SDK is bumped — see docs/mcp-sdk-upgrade.md.
        test.skip(true, 'Requires mcp/sdk ^0.6 object schemas; see docs/mcp-sdk-upgrade.md.');

        const profile = await readPublicProfile(api);
        const mcpEndpoint = shoppingTransports(profile).find((entry) => entry.transport === 'mcp')?.endpoint;

        test.skip(!mcpEndpoint, 'Store API MCP endpoint is not exposed by this lane.');

        const expectedTools = [
            'shopware-ucp-cart-create',
            'shopware-ucp-cart-update',
            'shopware-ucp-checkout-create',
            'shopware-ucp-checkout-update',
        ];

        const mcpApi = await request.newContext();
        const { sessionId } = await initializeMcpSession(mcpApi, mcpEndpoint);

        const toolsResponse = await mcpApi.post(mcpEndpoint, {
            headers: {
                'content-type': 'application/json',
                'Mcp-Session-Id': sessionId,
            },
            data: {
                jsonrpc: '2.0',
                method: 'tools/list',
                params: {},
                id: 202,
            },
        });

        await expect(toolsResponse, await toolsResponse.text()).toBeOK();
        const toolsBody = await toolsResponse.json();
        const tools = new Map(toolsBody.result.tools.map((tool) => [tool.name, tool]));

        for (const toolName of expectedTools) {
            const tool = tools.get(toolName);
            expect(tool, `${toolName} must be listed`).toBeTruthy();
            expect(tool.inputSchema.required).toContain('payload');
            expect(tool.inputSchema.properties.payload.type).toBe('object');
            expect(tool.inputSchema.properties.payload.default).toBeUndefined();
            expect(JSON.stringify(tool.inputSchema)).not.toContain('"default":{}');
        }

        for (const toolName of ['shopware-ucp-cart-update', 'shopware-ucp-checkout-update']) {
            expect(tools.get(toolName).inputSchema.required).toContain('id');
            expect(tools.get(toolName).inputSchema.properties.id.minLength).toBe(1);
        }

        const invalidCallResponse = await mcpApi.post(mcpEndpoint, {
            headers: {
                'content-type': 'application/json',
                'Mcp-Session-Id': sessionId,
            },
            data: {
                jsonrpc: '2.0',
                method: 'tools/call',
                params: {
                    name: 'shopware-ucp-cart-create',
                    arguments: {},
                },
                id: 203,
            },
        });

        await expect(invalidCallResponse, await invalidCallResponse.text()).toBeOK();
        const invalidCallBody = await invalidCallResponse.json();
        expect(invalidCallBody.error.code).toBe(-32602);
        expect(invalidCallBody.error.message).toContain('payload');

        await mcpApi.dispose();
    });
});
