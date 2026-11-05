import { expect } from '@playwright/test';
import type { APIResponse } from '@playwright/test';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import type { JsonRpcCallOptions } from '@fixtures/UcpAgent';

/** `/ucp/mcp` proxies core's Store API MCP server, which arrived in 6.7.12.0. */
export const STORE_API_MCP_MISSING = '<6.7.12.0';

export interface McpSession {
    call(method: string, params?: Record<string, unknown>, options?: JsonRpcCallOptions): Promise<APIResponse>
}

export async function openMcpSession(agent: FixtureTypes['UcpAgent']): Promise<McpSession> {
    const initializeResponse = await agent.mcp.call('initialize', {
        protocolVersion: '2025-03-26',
        capabilities: {},
        clientInfo: { name: 'ucp-acceptance', version: '1.0.0' },
    });
    expect(initializeResponse.ok(), await initializeResponse.text()).toBeTruthy();
    const sessionId = initializeResponse.headers()['mcp-session-id'];
    expect(sessionId, 'initialize answers with a session id').toEqual(expect.any(String));

    return { call: (method, params, options = {}) => agent.mcp.call(method, params, { ...options, headers: { ...options.headers, 'Mcp-Session-Id': sessionId } }) };
}

export async function toolOutput(toolCallResponse: APIResponse): Promise<Record<string, unknown>> {
    const { result } = (await toolCallResponse.json()) as { result?: { structuredContent?: Record<string, unknown>, content?: { text?: string }[] } };

    return result?.structuredContent ?? (JSON.parse(result?.content?.[0]?.text ?? '{}') as Record<string, unknown>);
}
