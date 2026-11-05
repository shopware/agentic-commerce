import crypto from 'node:crypto';
import { expect, request, test as base } from '@playwright/test';
import type { APIRequestContext, APIResponse } from '@playwright/test';
import { UcpAgentKeyring } from '@services/UcpAgentKeyring';
import { UcpSigner } from '@services/UcpSigner';
import type { SignatureKnobs, SignedRequest } from '@services/UcpSigner';
import type { TestAgent, UcpAgentProfileHostTypes } from './UcpAgentProfileHost';

const METHODS_CARRYING_IDEMPOTENCY_KEY = ['POST', 'PUT', 'PATCH', 'DELETE'];

export interface UcpCallOptions {
    body?: unknown
    rawBody?: string
    query?: Record<string, string>
    /** A fresh key is generated on POST, PUT, PATCH and DELETE; `false` sends none. */
    idempotencyKey?: string | false
    headers?: Record<string, string>
    knobs?: SignatureKnobs
}

export type JsonRpcCallOptions = Omit<UcpCallOptions, 'body' | 'query'>;

export interface UcpClient {
    send(method: string, path: string, options?: UcpCallOptions): Promise<APIResponse>
}

export interface JsonRpcClient {
    call(method: string, params?: Record<string, unknown>, options?: JsonRpcCallOptions): Promise<APIResponse>
}

type Delivery = 'signed' | 'unsigned';

const withTrailingSlash = (url: string): string => url.replace(/\/+$/, '') + '/';

/**
 * An external, spec-conformant shopping agent. It deliberately does not extend the ATS `Actor`,
 * which is bound to a browser page: binding an agent to a page would put Chromium on every
 * protocol journey.
 */
export class UcpAgent {
    readonly expects = expect;
    readonly baseUrl: string;

    constructor(
        readonly name: string,
        readonly identity: TestAgent,
        private readonly api: APIRequestContext,
        private readonly signer: UcpSigner,
        baseUrl: string,
    ) {
        this.baseUrl = withTrailingSlash(baseUrl);
    }

    at(baseUrl: string): UcpAgent {
        return new UcpAgent(this.name, this.identity, this.api, this.signer, baseUrl);
    }

    async attemptsTo(task: () => Promise<void>): Promise<void> {
        const taskName = task.name.replace(/([a-z0-9])([A-Z])/g, '$1 $2').toLowerCase();

        await base.step(`${this.name} attempts to ${taskName}`, task);
    }

    get rest(): UcpClient {
        return { send: (method, path, options) => this.send(method, path, options) };
    }

    get a2a(): JsonRpcClient {
        return this.jsonRpc('/ucp/a2a');
    }

    get mcp(): JsonRpcClient {
        return this.jsonRpc('/ucp/mcp');
    }

    get unsigned(): UcpClient {
        return { send: (method, path, options) => this.send(method, path, options, 'unsigned') };
    }

    tampered(knobs: SignatureKnobs): UcpClient {
        return { send: (method, path, options) => this.send(method, path, { ...options, knobs }) };
    }

    async signs(method: string, path: string, options: UcpCallOptions = {}): Promise<SignedRequest> {
        return this.signer.sign(this.requestParts(method, path, options), this.identity.kid, options.knobs ?? {});
    }

    private jsonRpc(path: string): JsonRpcClient {
        return {
            call: (method, params = {}, options = {}) => this.send('POST', path, {
                ...options,
                body: { jsonrpc: '2.0', id: crypto.randomUUID(), method, params },
            }),
        };
    }

    /** The body is serialised here, once: the digest and the wire body are the same buffer. */
    private requestParts(method: string, path: string, options: UcpCallOptions) {
        const url = new URL(path.replace(/^\//, ''), this.baseUrl);
        for (const [name, value] of Object.entries(options.query ?? {})) {
            url.searchParams.set(name, value);
        }

        // Playwright upper-cases the method it sends, so the signed method has to be upper case already.
        const sentMethod = method.toUpperCase();
        const headers: Record<string, string> = { 'UCP-Agent': this.identity.agentHeader, ...options.headers };
        if (METHODS_CARRYING_IDEMPOTENCY_KEY.includes(sentMethod) && options.idempotencyKey !== false) {
            headers['Idempotency-Key'] = options.idempotencyKey ?? crypto.randomUUID();
        }

        const bodyText = options.rawBody ?? (options.body === undefined ? undefined : JSON.stringify(options.body));
        const body = bodyText === undefined ? undefined : Buffer.from(bodyText, 'utf8');

        return { method: sentMethod, url: url.toString(), headers, body };
    }

    private async send(method: string, path: string, options: UcpCallOptions = {}, delivery: Delivery = 'signed'): Promise<APIResponse> {
        const parts = this.requestParts(method, path, options);

        if (delivery === 'unsigned') {
            const headers = parts.body === undefined ? parts.headers : { 'Content-Type': 'application/json', ...parts.headers };

            return this.api.fetch(parts.url, { method: parts.method, headers, data: parts.body });
        }

        const signed = await this.signer.sign(parts, this.identity.kid, options.knobs ?? {});

        return this.api.fetch(signed.url, { method: signed.method, headers: signed.headers, data: signed.body });
    }
}

export interface UcpAgentTypes {
    UcpAgentKeyring: UcpAgentKeyring
    UcpSigner: UcpSigner
    UcpAgent: UcpAgent
    UcpAgentB: UcpAgent
}

async function agentFor(name: string, identity: TestAgent, keyring: UcpAgentKeyring, signer: UcpSigner, use: (agent: UcpAgent) => Promise<void>): Promise<void> {
    await keyring.addPkcs8Pem(identity.kid, identity.privateKeyPem);
    const api = await request.newContext({ ignoreHTTPSErrors: true });

    try {
        await use(new UcpAgent(name, identity, api, signer, process.env.APP_URL ?? ''));
    }
    finally {
        await api.dispose();
    }
}

export const test = base.extend<NonNullable<unknown>, UcpAgentTypes & UcpAgentProfileHostTypes>({
    UcpAgentKeyring: [
        async ({}, use) => {
            await use(new UcpAgentKeyring());
        },
        { scope: 'worker' },
    ],

    UcpSigner: [
        async ({ UcpAgentKeyring: keyring }, use) => {
            await use(new UcpSigner(keyring));
        },
        { scope: 'worker' },
    ],

    UcpAgent: [
        async ({ UcpAgentProfileHost, UcpAgentKeyring: keyring, UcpSigner: signer }, use) => {
            await agentFor('Agent', await UcpAgentProfileHost.publish(), keyring, signer, use);
        },
        { scope: 'worker' },
    ],

    UcpAgentB: [
        async ({ UcpAgentProfileHost, UcpAgentKeyring: keyring, UcpSigner: signer }, use) => {
            await agentFor('Agent B', await UcpAgentProfileHost.publish(), keyring, signer, use);
        },
        { scope: 'worker' },
    ],
});
