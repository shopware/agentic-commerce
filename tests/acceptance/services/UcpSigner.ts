import crypto from 'node:crypto';
import type { UcpAgentKeyring } from './UcpAgentKeyring';

export const DEFAULT_SIGNATURE_LABEL = 'sig1';

const METHODS_CARRYING_IDEMPOTENCY_KEY = ['POST', 'PUT', 'PATCH', 'DELETE'];
const DEFAULT_PORTS: Record<string, string> = { 'http:': '80', 'https:': '443' };

export interface SignableRequest {
    method: string
    /** Absolute, so `@authority`, `@path` and `@query` all derive from one source. */
    url: string
    headers?: Record<string, string>
    /** Serialised exactly once by the caller; the digest and the wire body are this buffer. */
    body?: Buffer
}

/**
 * Deliberate deviations, one per negative spec. Every knob must change the signature base or
 * the signature bytes: a knob that changes nothing turns the spec that uses it into a false pass.
 */
export interface SignatureKnobs {
    /** Sign the real body, send a different one. */
    tamperBody?: Buffer
    /** Flip a bit in the signature after signing. */
    tamperSignatureBytes?: boolean
    replay?: SignedRequest
    /** RFC 9421 allows any label; UCP names `sig1`. */
    label?: string
    /** Cover `@target-uri`, which the spec does not use. */
    includeTargetUri?: boolean
    /** Drop `created`, which the spec makes optional. */
    omitCreated?: boolean
    /** Seconds added to `created`; negative ages the signature. */
    createdOffsetSeconds?: number
    /** Add `alg`, which the spec omits. */
    withAlg?: boolean
    /** Add `expires`, seconds after `created`. */
    withExpires?: number
}

export interface SignedRequest {
    method: string
    url: string
    headers: Record<string, string>
    body?: Buffer
    signatureBase: string
    signature: Buffer
    coveredComponents: string[]
}

export function contentDigest(body: Buffer): string {
    return `sha-256=:${crypto.createHash('sha256').update(body).digest('base64')}:`;
}

/** RFC 9421 §2.2.3: lower-case host, port only when it is not the scheme default. */
function authorityOf(url: URL): string {
    const host = url.hostname.toLowerCase();

    return url.port === '' || url.port === DEFAULT_PORTS[url.protocol] ? host : `${host}:${url.port}`;
}

function headerValue(headers: Record<string, string>, name: string): string | undefined {
    const match = Object.entries(headers).find(([key]) => key.toLowerCase() === name);

    return match?.[1].trim();
}

/**
 * Signs UCP requests per RFC 9421 with ES256, from the specification rather than from the
 * plugin's implementation. A signer that shared code with the server under test could only
 * prove that the implementation agrees with itself.
 */
export class UcpSigner {
    constructor(private readonly keyring: UcpAgentKeyring) {
    }

    /**
     * The covered components, in the order the UCP signature specification fixes. Order is part
     * of the signature base, so it is not a detail a caller may vary.
     */
    coveredComponents(request: SignableRequest, knobs: SignatureKnobs = {}): string[] {
        const url = new URL(request.url);
        const headers = request.headers ?? {};
        const components = ['@method', '@authority', '@path'];

        if (knobs.includeTargetUri) {
            components.push('@target-uri');
        }
        if (url.search !== '') {
            components.push('@query');
        }
        components.push('ucp-agent');
        if (headerValue(headers, 'signature-agent') !== undefined) {
            components.push('signature-agent');
        }
        if (METHODS_CARRYING_IDEMPOTENCY_KEY.includes(request.method.toUpperCase())
          && headerValue(headers, 'idempotency-key') !== undefined) {
            components.push('idempotency-key');
        }
        if (request.body !== undefined) {
            components.push('content-digest', 'content-type');
        }

        return components;
    }

    componentValue(request: SignableRequest, component: string): string {
        const url = new URL(request.url);
        const headers = request.headers ?? {};

        switch (component) {
            case '@method': return request.method;
            case '@authority': return authorityOf(url);
            case '@path': return url.pathname === '' ? '/' : url.pathname;
            case '@query': return url.search;
            case '@target-uri': return url.toString();
            default: {
                const value = headerValue(headers, component);
                if (value === undefined) {
                    throw new Error(`The signature covers "${component}", which the request does not carry.`);
                }

                return value;
            }
        }
    }

    signatureParams(components: string[], keyId: string, knobs: SignatureKnobs = {}, now = Math.floor(Date.now() / 1000)): string {
        const created = now + (knobs.createdOffsetSeconds ?? 0);
        const inner = components.map(component => `"${component}"`).join(' ');
        let params = `(${inner})`;

        if (!knobs.omitCreated) {
            params += `;created=${created}`;
        }
        if (knobs.withExpires !== undefined) {
            params += `;expires=${created + knobs.withExpires}`;
        }
        params += `;keyid="${keyId}"`;
        if (knobs.withAlg) {
            params += ';alg="ecdsa-p256-sha256"';
        }

        return params;
    }

    /**
     * RFC 9421 §2.5: one line per component, the parameters line last and unterminated. Takes any
     * component list, so the RFC's own examples can drive it.
     */
    signatureBase(request: SignableRequest, components: string[], params: string): string {
        const lines = components.map(component => `"${component}": ${this.componentValue(request, component)}`);
        lines.push(`"@signature-params": ${params}`);

        return lines.join('\n');
    }

    async sign(request: SignableRequest, kid: string, knobs: SignatureKnobs = {}): Promise<SignedRequest> {
        const headers = { ...(request.headers ?? {}) };

        if (headerValue(headers, 'ucp-agent') === undefined) {
            throw new Error('A UCP request is signed over its UCP-Agent header; this request has none.');
        }
        if (request.body !== undefined) {
            if (headerValue(headers, 'content-digest') === undefined) {
                headers['Content-Digest'] = contentDigest(request.body);
            }
            if (headerValue(headers, 'content-type') === undefined) {
                headers['Content-Type'] = 'application/json';
            }
        }

        const signable: SignableRequest = { ...request, headers };
        const components = this.coveredComponents(signable, knobs);
        const label = knobs.label ?? DEFAULT_SIGNATURE_LABEL;

        if (knobs.replay !== undefined) {
            return { ...knobs.replay, method: request.method, url: request.url };
        }

        const params = this.signatureParams(components, kid, knobs);
        const signatureBase = this.signatureBase(signable, components, params);
        const signature = await this.keyring.sign(kid, Buffer.from(signatureBase, 'utf8'));

        if (knobs.tamperSignatureBytes) {
            signature[0] ^= 0xff;
        }

        return {
            method: request.method,
            url: request.url,
            headers: {
                ...headers,
                'Signature-Input': `${label}=${params}`,
                Signature: `${label}=:${signature.toString('base64')}:`,
            },
            body: knobs.tamperBody ?? request.body,
            signatureBase,
            signature,
            coveredComponents: components,
        };
    }
}
