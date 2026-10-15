import crypto from 'node:crypto';

const ES256_SIGNATURE_BYTES = 64;

type Jwk = crypto.webcrypto.JsonWebKey;

export interface AgentKey {
    kid: string
    privateKey: crypto.webcrypto.CryptoKey
    publicKey: crypto.webcrypto.CryptoKey
    publicJwk: Jwk
}

const subtle = crypto.webcrypto.subtle;
const ES256 = { name: 'ECDSA', namedCurve: 'P-256' } as const;
const ES256_SIGN = { name: 'ECDSA', hash: 'SHA-256' } as const;

function webcryptoBytes(buffer: Buffer): Uint8Array<ArrayBuffer> {
    return Uint8Array.from(buffer);
}

function derFromPem(pem: string): Uint8Array<ArrayBuffer> {
    return webcryptoBytes(Buffer.from(pem.replace(/-----(BEGIN|END)[^-]+-----/g, '').replace(/\s+/g, ''), 'base64'));
}

async function publicFromPrivateJwk(privateJwk: Jwk): Promise<crypto.webcrypto.CryptoKey> {
    const { kty, crv, x, y } = privateJwk;

    return subtle.importKey('jwk', { kty, crv, x, y, ext: true }, ES256, true, ['verify']);
}

/**
 * Key material for the test agents, on Node's WebCrypto. ES256 signatures come out in the
 * fixed-width `r || s` form RFC 9421 requires, so nothing here touches DER.
 */
export class UcpAgentKeyring {
    private readonly keysByKid = new Map<string, AgentKey>();

    static async generate(kid: string): Promise<UcpAgentKeyring> {
        const keyring = new UcpAgentKeyring();
        await keyring.add(kid);

        return keyring;
    }

    async add(kid: string): Promise<AgentKey> {
        const keyPair = await subtle.generateKey(ES256, true, ['sign', 'verify']);

        return this.remember({
            kid,
            privateKey: keyPair.privateKey,
            publicKey: keyPair.publicKey,
            publicJwk: await subtle.exportKey('jwk', keyPair.publicKey),
        });
    }

    /** PKCS#8, the form `UcpAgentProfileHost` hands out. */
    async addPkcs8Pem(kid: string, pem: string): Promise<AgentKey> {
        const privateKey = await subtle.importKey('pkcs8', derFromPem(pem), ES256, true, ['sign']);
        const privateJwk = await subtle.exportKey('jwk', privateKey);
        const publicKey = await publicFromPrivateJwk(privateJwk);

        return this.remember({ kid, privateKey, publicKey, publicJwk: await subtle.exportKey('jwk', publicKey) });
    }

    /** The RFC 9421 Appendix B key is published as a JWK, its PEM being SEC1 rather than PKCS#8. */
    async addPrivateJwk(kid: string, privateJwk: Jwk): Promise<AgentKey> {
        const privateKey = await subtle.importKey('jwk', { ...privateJwk, ext: true }, ES256, true, ['sign']);
        const publicKey = await publicFromPrivateJwk(privateJwk);

        return this.remember({ kid, privateKey, publicKey, publicJwk: await subtle.exportKey('jwk', publicKey) });
    }

    get(kid: string): AgentKey {
        const agentKey = this.keysByKid.get(kid);
        if (agentKey === undefined) {
            throw new Error(`No key for kid "${kid}" in the keyring (have: ${[...this.keysByKid.keys()].join(', ') || 'none'}).`);
        }

        return agentKey;
    }

    async sign(kid: string, message: Buffer): Promise<Buffer> {
        const signature = Buffer.from(await subtle.sign(ES256_SIGN, this.get(kid).privateKey, webcryptoBytes(message)));
        if (signature.length !== ES256_SIGNATURE_BYTES) {
            throw new Error(`An ES256 signature is ${ES256_SIGNATURE_BYTES} bytes; this one is ${signature.length}.`);
        }

        return signature;
    }

    async verify(kid: string, signature: Buffer, message: Buffer): Promise<boolean> {
        return subtle.verify(ES256_SIGN, this.get(kid).publicKey, webcryptoBytes(signature), webcryptoBytes(message));
    }

    static async verifyWithJwk(publicJwk: Jwk, signature: Buffer, message: Buffer): Promise<boolean> {
        const publicKey = await subtle.importKey('jwk', { ...publicJwk, ext: true }, ES256, true, ['verify']);

        return subtle.verify(ES256_SIGN, publicKey, webcryptoBytes(signature), webcryptoBytes(message));
    }

    private remember(agentKey: AgentKey): AgentKey {
        this.keysByKid.set(agentKey.kid, agentKey);

        return agentKey;
    }
}

/** RFC 9421 Appendix B.1.3, the `test-key-ecc-p256` pair, JWK form. */
export const RFC9421_P256_PRIVATE_JWK: Jwk = {
    kty: 'EC',
    crv: 'P-256',
    d: 'UpuF81l-kOxbjf7T4mNSv0r5tN67Gim7rnf6EFpcYDs',
    x: 'qIVYZVLCrPZHGHjP17CTW0_-D9Lfw0EkjqF7xB4FivA',
    y: 'Mc4nN9LTDOBhfoUeg8Ye9WedFRhnZXZJA12Qp0zZ6F0',
};
