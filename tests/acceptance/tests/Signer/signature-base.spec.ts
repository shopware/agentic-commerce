import { expect, test } from '@playwright/test';
import { UcpAgentKeyring } from '@services/UcpAgentKeyring';
import { UcpSigner, contentDigest } from '@services/UcpSigner';

const AGENT_HEADER = 'profile="https://agent.example/profile.json"';

async function signerFor(kid = 'base-spec'): Promise<UcpSigner> {
    return new UcpSigner(await UcpAgentKeyring.generate(kid));
}

test.describe('Signature base construction @Signer', () => {
    test('covers the specification\'s components in the specification\'s order', async () => {
        const signer = await signerFor();

        const components = signer.coveredComponents({
            method: 'POST',
            url: 'https://shop.example/ucp/v1/carts?expand=items',
            headers: { 'UCP-Agent': AGENT_HEADER, 'Idempotency-Key': 'idem-1' },
            body: Buffer.from('{}', 'utf8'),
        });

        expect(components).toEqual([
            '@method', '@authority', '@path', '@query',
            'ucp-agent', 'idempotency-key', 'content-digest', 'content-type',
        ]);
    });

    test('omits the conditional components a request does not carry', async () => {
        const signer = await signerFor();

        const components = signer.coveredComponents({
            method: 'GET',
            url: 'https://shop.example/ucp/v1/carts/c1',
            headers: { 'UCP-Agent': AGENT_HEADER, 'Idempotency-Key': 'ignored-on-get' },
        });

        expect(components).toEqual(['@method', '@authority', '@path', 'ucp-agent']);
    });

    test('covers signature-agent only when the request carries it', async () => {
        const signer = await signerFor();
        const request = { method: 'GET', url: 'https://shop.example/ucp/v1/carts/c1', headers: { 'UCP-Agent': AGENT_HEADER } };

        expect(signer.coveredComponents(request)).not.toContain('signature-agent');
        expect(signer.coveredComponents({ ...request, headers: { ...request.headers, 'Signature-Agent': 'https://agent.example' } }))
            .toContain('signature-agent');
    });

    test('@authority carries the port only when it is not the scheme default', async () => {
        const signer = await signerFor();
        const authorityOf = (url: string) => signer.componentValue({ method: 'GET', url, headers: { 'UCP-Agent': AGENT_HEADER } }, '@authority');

        expect(authorityOf('https://Shop.Example/x')).toBe('shop.example');
        expect(authorityOf('https://shop.example:443/x')).toBe('shop.example');
        expect(authorityOf('http://shop.example:80/x')).toBe('shop.example');
        expect(authorityOf('https://shop.example:8443/x')).toBe('shop.example:8443');
        expect(authorityOf('http://localhost:8000/x')).toBe('localhost:8000');
    });

    test('@path is a single slash when the URL has none, and @query keeps its question mark', async () => {
        const signer = await signerFor();
        const valueOf = (url: string, component: string) => signer.componentValue({ method: 'GET', url, headers: { 'UCP-Agent': AGENT_HEADER } }, component);

        expect(valueOf('https://shop.example', '@path')).toBe('/');
        expect(valueOf('https://shop.example/test-abc/ucp/v1/carts', '@path')).toBe('/test-abc/ucp/v1/carts');
        expect(valueOf('https://shop.example/x?q=a%20b&r=2', '@query')).toBe('?q=a%20b&r=2');
    });

    test('@method keeps the case of the request\'s method, as RFC 9421 §2.2.1 requires', async () => {
        const signer = await signerFor();

        expect(signer.componentValue({ method: 'POST', url: 'https://shop.example/x', headers: { 'UCP-Agent': AGENT_HEADER } }, '@method')).toBe('POST');
        expect(signer.componentValue({ method: 'post', url: 'https://shop.example/x', headers: { 'UCP-Agent': AGENT_HEADER } }, '@method')).toBe('post');
    });

    test('the digest covers the bytes that are sent, multi-byte content included', () => {
        const body = Buffer.from(JSON.stringify({ name: 'Küchenmesser — 30€', emoji: '🔪' }), 'utf8');

        expect(contentDigest(body)).toMatch(/^sha-256=:[A-Za-z0-9+/]+=*:$/);
        expect(contentDigest(body)).not.toBe(contentDigest(Buffer.from(body.toString('utf8').replace('🔪', 'x'), 'utf8')));
        expect(contentDigest(Buffer.from('{}', 'utf8')))
            .toBe('sha-256=:RBNvo1WzZ4oRRq0W9+hknpT7T8If536DEMBg9hyq/4o=:');
    });

    test('the signature base is one line per component and ends with the parameters', async () => {
        const signer = await signerFor();
        const request = {
            method: 'POST',
            url: 'https://shop.example/ucp/v1/carts',
            headers: { 'UCP-Agent': AGENT_HEADER, 'Content-Type': 'application/json', 'Content-Digest': contentDigest(Buffer.from('{}', 'utf8')) },
            body: Buffer.from('{}', 'utf8'),
        };
        const components = signer.coveredComponents(request);
        const params = signer.signatureParams(components, 'base-spec', {}, 1700000000);

        const lines = signer.signatureBase(request, components, params).split('\n');

        expect(lines).toHaveLength(components.length + 1);
        expect(lines[0]).toBe('"@method": POST');
        expect(lines[1]).toBe('"@authority": shop.example');
        expect(lines[2]).toBe('"@path": /ucp/v1/carts');
        expect(lines.at(-1)).toBe(`"@signature-params": ${params}`);
        expect(signer.signatureBase(request, components, params).endsWith('\n')).toBe(false);
    });

    test('the parameters carry created and keyid, and nothing else', async () => {
        const signer = await signerFor();

        const params = signer.signatureParams(['@method'], 'agent-key-1', {}, 1700000000);

        expect(params).toBe('("@method");created=1700000000;keyid="agent-key-1"');
    });

    test('the label is sig1', async () => {
        const signer = await signerFor();

        const signed = await signer.sign({
            method: 'GET',
            url: 'https://shop.example/ucp/v1/carts/c1',
            headers: { 'UCP-Agent': AGENT_HEADER },
        }, 'base-spec');

        expect(signed.headers['Signature-Input']).toMatch(/^sig1=\("@method"/);
        expect(signed.headers.Signature).toMatch(/^sig1=:[A-Za-z0-9+/]+=*:$/);
    });

    test('signing refuses a request without a UCP-Agent header', async () => {
        const signer = await signerFor();

        await expect(signer.sign({ method: 'GET', url: 'https://shop.example/x' }, 'base-spec'))
            .rejects.toThrow(/UCP-Agent/);
    });

    test('a body gets a digest and a content type even when the caller sent neither', async () => {
        const signer = await signerFor();

        const signed = await signer.sign({
            method: 'POST',
            url: 'https://shop.example/ucp/v1/carts',
            headers: { 'UCP-Agent': AGENT_HEADER },
            body: Buffer.from('{"a":1}', 'utf8'),
        }, 'base-spec');

        expect(signed.headers['Content-Digest']).toBe(contentDigest(Buffer.from('{"a":1}', 'utf8')));
        expect(signed.headers['Content-Type']).toBe('application/json');
        expect(signed.signatureBase).toContain(`"content-digest": ${signed.headers['Content-Digest']}`);
    });
});
