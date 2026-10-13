import { expect, test } from '@playwright/test';
import { UcpAgentKeyring } from '@services/UcpAgentKeyring';
import { UcpSigner, contentDigest } from '@services/UcpSigner';
import type { SignatureKnobs } from '@services/UcpSigner';

const KID = 'knob-spec';
const AGENT_HEADER = 'profile="https://agent.example/profile.json"';

const A_SIGNED_REQUEST = {
    method: 'POST',
    url: 'https://shop.example/ucp/v1/carts?expand=items',
    headers: { 'UCP-Agent': AGENT_HEADER, 'Idempotency-Key': 'idem-1' },
    body: Buffer.from('{"items":[]}', 'utf8'),
};

async function signWith(knobs: SignatureKnobs) {
    const signer = new UcpSigner(await UcpAgentKeyring.generate(KID));
    const conformant = await signer.sign(A_SIGNED_REQUEST, KID);
    const deviating = await signer.sign(A_SIGNED_REQUEST, KID, knobs);

    return { conformant, deviating };
}

/**
 * A knob that leaves the signature base and the signature bytes untouched turns every negative
 * spec that uses it into a false pass.
 */
test.describe('Signature knobs @Signer', () => {
    test('includeTargetUri adds the component the specification does not use', async () => {
        const { conformant, deviating } = await signWith({ includeTargetUri: true });

        expect(conformant.coveredComponents).not.toContain('@target-uri');
        expect(deviating.coveredComponents).toContain('@target-uri');
        expect(deviating.signatureBase).toContain('"@target-uri": https://shop.example/ucp/v1/carts?expand=items');
    });

    test('omitCreated drops the parameter the specification makes optional', async () => {
        const { conformant, deviating } = await signWith({ omitCreated: true });

        expect(conformant.headers['Signature-Input']).toContain(';created=');
        expect(deviating.headers['Signature-Input']).not.toContain(';created=');
        expect(deviating.headers['Signature-Input']).toContain(';keyid="knob-spec"');
    });

    test('a negative created offset ages the signature', async () => {
        const now = Math.floor(Date.now() / 1000);
        const { deviating } = await signWith({ createdOffsetSeconds: -400 });

        const created = Number(/;created=(\d+)/.exec(deviating.headers['Signature-Input'])?.[1]);
        expect(created).toBeLessThanOrEqual(now - 399);
    });

    test('a positive created offset dates the signature into the future', async () => {
        const now = Math.floor(Date.now() / 1000);
        const { deviating } = await signWith({ createdOffsetSeconds: 400 });

        const created = Number(/;created=(\d+)/.exec(deviating.headers['Signature-Input'])?.[1]);
        expect(created).toBeGreaterThanOrEqual(now + 399);
    });

    test('withAlg adds the parameter the specification omits', async () => {
        const { conformant, deviating } = await signWith({ withAlg: true });

        expect(conformant.signatureBase).not.toContain(';alg=');
        expect(deviating.signatureBase).toContain(';alg="ecdsa-p256-sha256"');
    });

    test('withExpires adds an expiry relative to created', async () => {
        const { conformant, deviating } = await signWith({ withExpires: 300 });

        expect(conformant.signatureBase).not.toContain(';expires=');

        const signatureInputHeader = deviating.headers['Signature-Input'];
        const created = Number(/;created=(\d+)/.exec(signatureInputHeader)?.[1]);
        const expires = Number(/;expires=(\d+)/.exec(signatureInputHeader)?.[1]);
        expect(expires - created).toBe(300);
    });

    test('label renames the signature, in both headers', async () => {
        const { deviating } = await signWith({ label: 'sig' });

        expect(deviating.headers['Signature-Input']).toMatch(/^sig=\(/);
        expect(deviating.headers.Signature).toMatch(/^sig=:/);
    });

    test('tamperBody sends bytes the digest does not cover', async () => {
        const tampered = Buffer.from('{"items":[{"id":"injected"}]}', 'utf8');
        const { conformant, deviating } = await signWith({ tamperBody: tampered });

        expect(conformant.body).toEqual(A_SIGNED_REQUEST.body);
        expect(deviating.body).toEqual(tampered);
        expect(deviating.headers['Content-Digest']).toBe(contentDigest(A_SIGNED_REQUEST.body));
        expect(deviating.headers['Content-Digest']).not.toBe(contentDigest(tampered));
    });

    test('tamperSignatureBytes breaks verification while keeping the length', async () => {
        const keyring = await UcpAgentKeyring.generate(KID);
        const signer = new UcpSigner(keyring);

        const tamperedSignature = await signer.sign(A_SIGNED_REQUEST, KID, { tamperSignatureBytes: true });

        expect(tamperedSignature.signature).toHaveLength(64);
        expect(await keyring.verify(KID, tamperedSignature.signature, Buffer.from(tamperedSignature.signatureBase, 'utf8'))).toBe(false);
    });

    test('replay resends the earlier signature bytes, where a fresh signing would not', async () => {
        const signer = new UcpSigner(await UcpAgentKeyring.generate(KID));

        const original = await signer.sign(A_SIGNED_REQUEST, KID);
        const resigned = await signer.sign(A_SIGNED_REQUEST, KID);
        const replayed = await signer.sign(A_SIGNED_REQUEST, KID, { replay: original });

        expect(resigned.signature.equals(original.signature)).toBe(false);
        expect(replayed.signature.equals(original.signature)).toBe(true);
        expect(replayed.headers.Signature).toBe(original.headers.Signature);
        expect(replayed.headers['Signature-Input']).toBe(original.headers['Signature-Input']);
    });

    test('two signatures of the same request differ, because ECDSA is randomised', async () => {
        const signer = new UcpSigner(await UcpAgentKeyring.generate(KID));

        const first = await signer.sign(A_SIGNED_REQUEST, KID);
        const second = await signer.sign(A_SIGNED_REQUEST, KID);

        expect(first.signature.equals(second.signature)).toBe(false);
    });
});
