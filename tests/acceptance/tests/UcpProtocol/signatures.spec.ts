import { expect, test } from '@fixtures/AcceptanceTest';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import type { APIResponse } from '@playwright/test';

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

async function messageCodes(response: APIResponse): Promise<string[]> {
    const { messages = [] } = (await response.json()) as { messages?: { code?: string }[] };

    return messages.map(message => message.code ?? '');
}

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

    test(`the strict policy refuses ${tamperedBody.name} with a client error`, async ({ TestDataService, UcpAgent }) => {
        const strictChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(strictChannel.salesChannel.id, { signaturePolicy: 'strict' });

        const refusal = await tamperedBody.send(UcpAgent.at(strictChannel.url));

        expect(refusal.status(), await refusal.text()).toBeGreaterThanOrEqual(400);
        expect(refusal.status(), await refusal.text()).toBeLessThan(500);
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
