import crypto from 'node:crypto';
import { expect, test } from '@playwright/test';
import { RFC9421_P256_PRIVATE_JWK, UcpAgentKeyring } from '@services/UcpAgentKeyring';
import { UcpSigner } from '@services/UcpSigner';

const RFC9421_TEST_REQUEST = {
    method: 'POST',
    url: 'https://example.com/foo?param=Value&Pet=dog',
    headers: {
        Date: 'Tue, 20 Apr 2021 02:07:55 GMT',
        'Content-Type': 'application/json',
        'Content-Digest': 'sha-512=:WZDPaVn/7XgHaAy8pmojAkGWoRx2UFChF41A2svX+TaPm+AbwAgBWnrIiYllu7BNNyealdVLvRwEmTHWXvJwew==:',
        'Content-Length': '18',
    },
    body: Buffer.from('{"hello": "world"}', 'utf8'),
};

const FULL_COVERAGE_COMPONENTS = ['date', '@method', '@path', '@query', '@authority', 'content-type', 'content-digest', 'content-length'];
const FULL_COVERAGE_PARAMS = '("date" "@method" "@path" "@query" "@authority" "content-type" "content-digest" "content-length");created=1618884473;keyid="test-key-rsa-pss"';
const FULL_COVERAGE_SIGNATURE_BASE = [
    '"date": Tue, 20 Apr 2021 02:07:55 GMT',
    '"@method": POST',
    '"@path": /foo',
    '"@query": ?param=Value&Pet=dog',
    '"@authority": example.com',
    '"content-type": application/json',
    '"content-digest": sha-512=:WZDPaVn/7XgHaAy8pmojAkGWoRx2UFChF41A2svX+TaPm+AbwAgBWnrIiYllu7BNNyealdVLvRwEmTHWXvJwew==:',
    '"content-length": 18',
    `"@signature-params": ${FULL_COVERAGE_PARAMS}`,
].join('\n');

const ED25519_COMPONENTS = ['date', '@method', '@path', '@authority', 'content-type', 'content-length'];
const ED25519_PARAMS = '("date" "@method" "@path" "@authority" "content-type" "content-length");created=1618884473;keyid="test-key-ed25519"';
const ED25519_SIGNATURE = 'wqcAqbmYJ2ji2glfAMaRy4gruYYnx2nEFN2HN6jrnDnQCK1u02Gb04v9EDgwUPiu4A0w6vuQv5lIp5WPpBKRCw==';

const RFC9421_ED25519_PRIVATE_JWK: crypto.webcrypto.JsonWebKey = {
    kty: 'OKP',
    crv: 'Ed25519',
    d: 'n4Ni-HpISpVObnQMW0wOhCKROaIKqKtW_2ZYb2p9KcU',
    x: 'JrQLj5P_89iXES9-vFgrIy29clF9CC_oPPsw3c5D0bs',
};

const P256_RESPONSE_SIGNATURE_BASE = [
    '"@status": 200',
    '"content-type": application/json',
    '"content-digest": sha-512=:mEWXIS7MaLRuGgxOBdODa3xqM1XdEvxoYhvlCFJ41QJgJc4GTsPp29l5oGX69wWdXymyU0rjJuahq4l5aGgfLQ==:',
    '"content-length": 23',
    '"@signature-params": ("@status" "content-type" "content-digest" "content-length");created=1618884473;keyid="test-key-ecc-p256"',
].join('\n');
const P256_RESPONSE_SIGNATURE = 'wNmSUAhwb5LxtOtOpNa6W5xj067m5hFrj0XQ4fvpaCLx0NKocgPquLgyahnzDnDAUy5eCdlYUEkLIj+32oiasw==';

const A_UCP_REQUEST = {
    method: 'POST',
    url: 'https://shop.example/ucp/v1/carts',
    headers: { 'UCP-Agent': 'profile="https://agent.example/profile.json"' },
    body: Buffer.from('{"items":[]}', 'utf8'),
};

test.describe('RFC 9421 known answers @Signer', () => {
    test('the signature base of the full-coverage request example matches the RFC byte for byte', () => {
        const signer = new UcpSigner(new UcpAgentKeyring());

        expect(signer.signatureBase(RFC9421_TEST_REQUEST, FULL_COVERAGE_COMPONENTS, FULL_COVERAGE_PARAMS)).toBe(FULL_COVERAGE_SIGNATURE_BASE);
    });

    test('the Ed25519 request example signs to the RFC\'s signature over the base this signer builds', async () => {
        // Ed25519 is deterministic, so here the signature bytes can be reproduced, not only verified.
        const signer = new UcpSigner(new UcpAgentKeyring());
        const signatureBase = Buffer.from(signer.signatureBase(RFC9421_TEST_REQUEST, ED25519_COMPONENTS, ED25519_PARAMS), 'utf8');
        const privateKey = await crypto.webcrypto.subtle.importKey('jwk', RFC9421_ED25519_PRIVATE_JWK, { name: 'Ed25519' }, false, ['sign']);

        const signature = Buffer.from(await crypto.webcrypto.subtle.sign({ name: 'Ed25519' }, privateKey, Uint8Array.from(signatureBase)));

        expect(signature.toString('base64')).toBe(ED25519_SIGNATURE);
    });

    test('the published P-256 signature verifies against the published key', async () => {
        const verified = await UcpAgentKeyring.verifyWithJwk(
            { kty: 'EC', crv: 'P-256', x: RFC9421_P256_PRIVATE_JWK.x, y: RFC9421_P256_PRIVATE_JWK.y },
            Buffer.from(P256_RESPONSE_SIGNATURE, 'base64'),
            Buffer.from(P256_RESPONSE_SIGNATURE_BASE, 'utf8'),
        );

        expect(verified, 'RFC 9421 B.2.4 must verify, or this keyring reads the RFC wrongly').toBe(true);
        expect(Buffer.from(P256_RESPONSE_SIGNATURE, 'base64')).toHaveLength(64);
    });

    test('the RFC P-256 key imports from its JWK form and signs 64 bytes that verify', async () => {
        const keyring = new UcpAgentKeyring();
        await keyring.addPrivateJwk('test-key-ecc-p256', RFC9421_P256_PRIVATE_JWK);

        const signature = await keyring.sign('test-key-ecc-p256', Buffer.from(P256_RESPONSE_SIGNATURE_BASE, 'utf8'));

        expect(signature).toHaveLength(64);
        expect(await keyring.verify('test-key-ecc-p256', signature, Buffer.from(P256_RESPONSE_SIGNATURE_BASE, 'utf8'))).toBe(true);
    });

    test('every ES256 signature is 64 bytes, never a left-truncated 63', async () => {
        const keyring = await UcpAgentKeyring.generate('truncation-check');

        for (let attempt = 0; attempt < 256; attempt++) {
            expect(await keyring.sign('truncation-check', Buffer.from(`attempt-${attempt}`, 'utf8'))).toHaveLength(64);
        }
    });

    test('a signed request verifies with the agent\'s published public key', async () => {
        const keyring = await UcpAgentKeyring.generate('round-trip');
        const signer = new UcpSigner(keyring);

        const signed = await signer.sign(A_UCP_REQUEST, 'round-trip');

        expect(await UcpAgentKeyring.verifyWithJwk(
            keyring.get('round-trip').publicJwk,
            signed.signature,
            Buffer.from(signed.signatureBase, 'utf8'),
        )).toBe(true);
    });

    test('a signature does not verify over a different signature base', async () => {
        const keyring = await UcpAgentKeyring.generate('round-trip');
        const signer = new UcpSigner(keyring);

        const signed = await signer.sign(A_UCP_REQUEST, 'round-trip');
        const baseWithAnotherMethod = signed.signatureBase.replace('"@method": POST', '"@method": GET');

        expect(await keyring.verify('round-trip', signed.signature, Buffer.from(baseWithAnotherMethod, 'utf8'))).toBe(false);
    });
});
