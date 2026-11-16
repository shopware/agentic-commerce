import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import type { APIRequestContext, APIResponse } from '@playwright/test';
import { messageCodes } from '@services/ucpMessages';

type UcpAgent = FixtureTypes['UcpAgent'];

const CATALOG_SEARCH_PATH = '/ucp/v1/catalog/search';
const catalogSearch = { body: { query: 'product' } };

interface SignatureDeviation {
    name: string
    send(agent: UcpAgent): Promise<APIResponse>
}

const unsignedRequest: SignatureDeviation = {
    name: 'an unsigned request',
    send: agent => agent.unsigned.send('POST', CATALOG_SEARCH_PATH, catalogSearch),
};

const tamperedBody: SignatureDeviation = {
    name: 'a body other than the signed one',
    send: agent => agent.tampered({ tamperBody: Buffer.from(JSON.stringify({ query: 'tampered' }), 'utf8') }).send('POST', CATALOG_SEARCH_PATH, catalogSearch),
};

const invalidSignatures: SignatureDeviation[] = [
    {
        name: 'altered signature bytes',
        send: agent => agent.tampered({ tamperSignatureBytes: true }).send('POST', CATALOG_SEARCH_PATH, catalogSearch),
    },
    {
        name: 'a signature created 400 seconds ago',
        send: agent => agent.tampered({ createdOffsetSeconds: -400 }).send('POST', CATALOG_SEARCH_PATH, catalogSearch),
    },
    {
        name: 'a signature created 400 seconds ahead',
        send: agent => agent.tampered({ createdOffsetSeconds: 400 }).send('POST', CATALOG_SEARCH_PATH, catalogSearch),
    },
];

test.describe('Request signatures @UcpProtocol', () => {
    test('the strict policy accepts a correctly signed request', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { signaturePolicy: 'strict' });

        const signedSearchResponse = await UcpAgent.at(strictChannel.url).rest.send('POST', CATALOG_SEARCH_PATH, catalogSearch);

        expect(signedSearchResponse.status(), await signedSearchResponse.text()).toBe(200);
    });

    test('the strict policy accepts a signed query in the order the agent sent it', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { signaturePolicy: 'strict' });

        const strictVerdictOnUnsortedQuery = await UcpAgent.at(strictChannel.url).rest.send('POST', CATALOG_SEARCH_PATH, { ...catalogSearch, query: { b: '1', a: '2' } });

        expect(strictVerdictOnUnsortedQuery.status(), await strictVerdictOnUnsortedQuery.text()).toBe(200);
    });

    test(`the strict policy refuses ${unsignedRequest.name} with 401`, async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { signaturePolicy: 'strict' });

        const refusal = await unsignedRequest.send(UcpAgent.at(strictChannel.url));

        expect(refusal.status(), await refusal.text()).toBe(401);
    });

    test(`the strict policy refuses ${tamperedBody.name} with 400 or 401`, async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { signaturePolicy: 'strict' });

        const refusal = await tamperedBody.send(UcpAgent.at(strictChannel.url));

        expect([400, 401], await refusal.text()).toContain(refusal.status());
    });

    for (const invalidSignature of invalidSignatures) {
        test(`the strict policy refuses ${invalidSignature.name} as signature_invalid`, async ({ TestDataService, UcpAgent }) => {
            const strictChannel = await TestDataService.createStorefrontSalesChannel();
            await TestDataService.activateUcp(strictChannel.salesChannel.id, { signaturePolicy: 'strict' });

            const refusal = await invalidSignature.send(UcpAgent.at(strictChannel.url));

            expect(refusal.status(), await refusal.text()).toBe(401);
            expect(await messageCodes(refusal)).toContain('signature_invalid');
        });
    }

    for (const signatureDeviation of [unsignedRequest, tamperedBody, ...invalidSignatures]) {
        test(`the log policy accepts ${signatureDeviation.name}`, async ({ TestDataService, UcpAgent }) => {
            const logChannel = await TestDataService.createStorefrontSalesChannel();
            await TestDataService.activateUcp(logChannel.salesChannel.id, { signaturePolicy: 'log' });

            const logPolicyVerdict = await signatureDeviation.send(UcpAgent.at(logChannel.url));

            expect(logPolicyVerdict.status(), await logPolicyVerdict.text()).toBe(200);
        });
    }
});

const A2A_PATH = '/ucp/a2a';
const a2aTransports = { enabledTransports: ['rest', 'a2a'] };

const jsonRpc = (method: string, params: Record<string, unknown>) => ({ jsonrpc: '2.0', id: crypto.randomUUID(), method, params });

const a2aCatalogSearch = () => ({ body: jsonRpc('catalog.search', { query: 'product' }) });

interface UcpRefusalBody {
    result?: unknown
    ucp?: { status?: string }
    messages?: { code?: string, content?: string }[]
}

/** The REST refusal on A2A too: a 401 with a UCP error body and never a JSON-RPC result. */
async function expectSignatureRefusal(response: APIResponse, content?: string): Promise<void> {
    expect(response.status(), await response.text()).toBe(401);
    const body = (await response.json()) as UcpRefusalBody;
    expect(body.result, 'a refused call carries no JSON-RPC result').toBeUndefined();
    expect(body.ucp?.status).toBe('error');
    expect(body.messages?.[0]?.code).toBe('signature_invalid');
    if (content !== undefined) {
        expect(body.messages?.[0]?.content).toBe(content);
    }
}

async function expectA2aResult(response: APIResponse): Promise<void> {
    expect(response.status(), await response.text()).toBe(200);
    const body = (await response.json()) as { result?: unknown, error?: unknown };
    expect(body.error, await response.text()).toBeUndefined();
    expect(body.result, await response.text()).toBeDefined();
}

/** Signs the call as the agent would, then sends it without one of the signature headers. */
async function sendA2aWithoutHeader(agent: UcpAgent, request: APIRequestContext, header: string): Promise<APIResponse> {
    const signed = await agent.signs('POST', A2A_PATH, a2aCatalogSearch());
    const headers = Object.fromEntries(Object.entries(signed.headers).filter(([name]) => name.toLowerCase() !== header.toLowerCase()));

    return request.fetch(signed.url, { method: signed.method, headers, data: signed.body });
}

test.describe('A2A request signatures @UcpProtocol', () => {
    test('the strict policy accepts a correctly signed A2A call', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { ...a2aTransports, signaturePolicy: 'strict' });

        await expectA2aResult(await UcpAgent.at(strictChannel.url).rest.send('POST', A2A_PATH, a2aCatalogSearch()));
    });

    test('the strict policy refuses an unsigned A2A call with the same 401 as REST', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { ...a2aTransports, signaturePolicy: 'strict' });
        const agent = UcpAgent.at(strictChannel.url);

        await expectSignatureRefusal(await agent.unsigned.send('POST', A2A_PATH, a2aCatalogSearch()), 'Missing signature headers.');
        await expectSignatureRefusal(await unsignedRequest.send(agent), 'Missing signature headers.');
    });

    test('the strict policy refuses unsigned A2A cart and checkout calls and authenticates before resolving the method', async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { ...a2aTransports, signaturePolicy: 'strict' });
        const product = await TestDataService.createBasicProduct({ visibilities: [{ salesChannelId: strictChannel.salesChannel.id, visibility: 30 }] });
        const agent = UcpAgent.at(strictChannel.url);
        const lineItems = [{ item: { id: product.id }, quantity: 1 }];

        for (const call of [
            jsonRpc('cart.create', { line_items: lineItems }),
            jsonRpc('checkout.create', { line_items: lineItems }),
            jsonRpc('checkout.complete', { id: crypto.randomUUID() }),
            jsonRpc('message/send', { message: { role: 'user', kind: 'message', messageId: crypto.randomUUID(), parts: [{ kind: 'text', text: 'show me the catalog' }] } }),
        ]) {
            await expectSignatureRefusal(await agent.unsigned.send('POST', A2A_PATH, { body: call }), 'Missing signature headers.');
        }
    });

    test('the strict policy refuses A2A calls whose signature does not verify', async ({ TestDataService, UcpAgent, UcpAgentB, request }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { ...a2aTransports, signaturePolicy: 'strict' });
        const agent = UcpAgent.at(strictChannel.url);
        const tamperedBody = Buffer.from(JSON.stringify(jsonRpc('catalog.search', { query: 'tampered' })), 'utf8');

        await expectSignatureRefusal(await sendA2aWithoutHeader(agent, request, 'Signature'), 'Missing signature headers.');
        await expectSignatureRefusal(await sendA2aWithoutHeader(agent, request, 'Signature-Input'), 'Missing signature headers.');
        await expectSignatureRefusal(await agent.tampered({ tamperSignatureBytes: true }).send('POST', A2A_PATH, a2aCatalogSearch()));
        await expectSignatureRefusal(await agent.tampered({ tamperBody: tamperedBody }).send('POST', A2A_PATH, a2aCatalogSearch()));
        // Signed with this agent's key while naming another agent's profile, which does not publish that key.
        await expectSignatureRefusal(await agent.rest.send('POST', A2A_PATH, { ...a2aCatalogSearch(), headers: { 'UCP-Agent': UcpAgentB.identity.agentHeader } }));
    });

    for (const signaturePolicy of ['log', 'off']) {
        test(`the ${signaturePolicy} policy accepts an unsigned A2A call`, async ({ TestDataService, UcpAgent }) => {
            const channel = await TestDataService.createStorefrontSalesChannel();
            await TestDataService.activateUcp(channel.salesChannel.id, { ...a2aTransports, signaturePolicy });

            await expectA2aResult(await UcpAgent.at(channel.url).unsigned.send('POST', A2A_PATH, a2aCatalogSearch()));
        });
    }
});
