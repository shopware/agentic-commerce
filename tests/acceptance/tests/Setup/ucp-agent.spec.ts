import { expect, test } from '@fixtures/AcceptanceTest';
import { UcpAgentKeyring } from '@services/UcpAgentKeyring';
import type { PublicSigningKeyJwk } from '@fixtures/UcpAgentProfileHost';
import { contentDigest } from '@services/UcpSigner';

const MULTI_BYTE_BODY = { line_items: [{ item: { id: 'knife' }, quantity: 1 }], note: 'Küchenmesser, 30 € 🔪' };
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

test.describe('Test agents @Setup', () => {
    test('a signed POST sends the bytes its digest covers', async ({ UcpAgent }) => {
        const signed = await UcpAgent.signs('POST', '/ucp/carts', { body: MULTI_BYTE_BODY });

        expect(signed.body).toEqual(Buffer.from(JSON.stringify(MULTI_BYTE_BODY), 'utf8'));
        expect(signed.headers['Content-Digest']).toBe(contentDigest(signed.body ?? Buffer.alloc(0)));
        expect(signed.headers['UCP-Agent']).toBe(UcpAgent.identity.agentHeader);
    });

    test('a signed request verifies with the key the agent published', async ({ UcpAgent, request }) => {
        const signed = await UcpAgent.signs('POST', '/ucp/carts', { body: MULTI_BYTE_BODY });
        const servedProfile = await request.get(new URL(`ucp-acceptance-agents/${UcpAgent.identity.kid}.json`, process.env.APP_URL).toString());
        const { keys } = (await servedProfile.json()) as { keys: PublicSigningKeyJwk[] };

        expect(keys).toHaveLength(1);
        expect(await UcpAgentKeyring.verifyWithJwk(keys[0], signed.signature, Buffer.from(signed.signatureBase, 'utf8'))).toBe(true);
    });

    test('every write gets its own idempotency key unless the call passes one or opts out', async ({ UcpAgent }) => {
        const firstWrite = await UcpAgent.signs('POST', '/ucp/carts', { body: {} });
        const secondWrite = await UcpAgent.signs('POST', '/ucp/carts', { body: {} });
        const read = await UcpAgent.signs('GET', '/ucp/carts/c1');
        const writeWithOwnKey = await UcpAgent.signs('PUT', '/ucp/carts/c1', { body: {}, idempotencyKey: 'own-key' });
        const writeWithoutKey = await UcpAgent.signs('DELETE', '/ucp/carts/c1', { idempotencyKey: false });

        expect(firstWrite.headers['Idempotency-Key']).toMatch(UUID);
        expect(secondWrite.headers['Idempotency-Key']).not.toBe(firstWrite.headers['Idempotency-Key']);
        expect(firstWrite.coveredComponents).toContain('idempotency-key');
        expect(read.headers['Idempotency-Key']).toBeUndefined();
        expect(writeWithOwnKey.headers['Idempotency-Key']).toBe('own-key');
        expect(writeWithoutKey.headers['Idempotency-Key']).toBeUndefined();
        expect(writeWithoutKey.coveredComponents).not.toContain('idempotency-key');
    });

    test('an agent pointed at a channel keeps its path prefix, with or without a trailing slash', async ({ UcpAgent }) => {
        const withoutSlash = await UcpAgent.at('https://shop.example/test-abc').signs('GET', '/ucp/carts/c1');
        const withSlash = await UcpAgent.at('https://shop.example/test-abc/').signs('GET', 'ucp/carts/c1');

        expect(withoutSlash.url).toBe('https://shop.example/test-abc/ucp/carts/c1');
        expect(withSlash.url).toBe('https://shop.example/test-abc/ucp/carts/c1');
        expect(withoutSlash.signatureBase).toContain('"@path": /test-abc/ucp/carts/c1');
    });

    test('the second agent has its own key and its own profile', ({ UcpAgent, UcpAgentB }) => {
        expect(UcpAgentB.identity.kid).not.toBe(UcpAgent.identity.kid);
        expect(UcpAgentB.identity.profileUrl).not.toBe(UcpAgent.identity.profileUrl);
        expect(UcpAgentB.identity.publicJwk.x).not.toBe(UcpAgent.identity.publicJwk.x);
    });
});
