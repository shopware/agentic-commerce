import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { request, test as base } from '@playwright/test';
import { readUcpProtocolVersion, resolveShopwareDir } from '@services/pluginSource';

export const AGENT_PROFILE_DIRECTORY = 'ucp-acceptance-agents';
const DEFAULT_AGENT_PROFILE_BASE_URL = 'http://localhost:8000';

export interface PublicSigningKeyJwk {
    kid: string
    kty: 'EC'
    alg: 'ES256'
    use: 'sig'
    crv: 'P-256'
    x: string
    y: string
}

export interface TestAgent {
    kid: string
    label: string
    /** Where the shop fetches the profile from; a `localhost` URL as seen from the web container. */
    profileUrl: string
    agentHeader: string
    publicJwk: PublicSigningKeyJwk
    /** PKCS#8 PEM, for signing requests as this agent. */
    privateKeyPem: string
    profile: Record<string, unknown>
}

export interface CapabilityEntry {
    version: string
    spec: string
    schema: string
    extends?: string[]
}

export type CapabilityMap = Record<string, CapabilityEntry[]>;

export interface PublishOptions {
    kid?: string
    label?: string
    /** Replaces the default set entirely; `{}` publishes an agent that can negotiate nothing. */
    capabilities?: CapabilityMap
}

export interface UcpAgentProfileHost {
    host: string
    directory: string
    publish(options?: PublishOptions): Promise<TestAgent>
}

const SHOPPING_CAPABILITIES: { name: string, document: string, extends?: string[] }[] = [
    { name: 'dev.ucp.shopping.catalog.search', document: 'catalog' },
    { name: 'dev.ucp.shopping.catalog.lookup', document: 'catalog' },
    { name: 'dev.ucp.shopping.cart', document: 'cart' },
    { name: 'dev.ucp.shopping.checkout', document: 'checkout' },
    { name: 'dev.ucp.shopping.discount', document: 'discount', extends: ['dev.ucp.shopping.cart', 'dev.ucp.shopping.checkout'] },
    { name: 'dev.ucp.shopping.order', document: 'order' },
];

/**
 * Every shopping capability the UCP specification defines, at the given protocol version. The SDK
 * negotiates on name, version and `extends`, so an agent that publishes none gets
 * `capabilities_incompatible` on every operation.
 */
export function specShoppingCapabilities(version: string): CapabilityMap {
    return Object.fromEntries(SHOPPING_CAPABILITIES.map(capability => [capability.name, [{
        version,
        spec: `https://ucp.dev/specification/${capability.document}/`,
        schema: `https://ucp.dev/${version}/schemas/shopping/${capability.document}.json`,
        ...(capability.extends === undefined ? {} : { extends: capability.extends }),
    }]]));
}

export interface UcpAgentProfileHostTypes {
    UcpAgentProfileHost: UcpAgentProfileHost
}

function agentProfileBaseUrl(): URL {
    return new URL((process.env.UCP_AGENT_PROFILE_BASE_URL ?? DEFAULT_AGENT_PROFILE_BASE_URL).replace(/\/+$/, '') + '/');
}

export function agentProfileHost(): string {
    return agentProfileBaseUrl().hostname;
}

function generateSigningKey(kid: string): { publicJwk: PublicSigningKeyJwk, privateKeyPem: string } {
    const { publicKey, privateKey } = crypto.generateKeyPairSync('ec', { namedCurve: 'P-256' });
    const jwk = publicKey.export({ format: 'jwk' }) as { x: string, y: string };

    return {
        publicJwk: { kid, kty: 'EC', alg: 'ES256', use: 'sig', crv: 'P-256', x: jwk.x, y: jwk.y },
        privateKeyPem: privateKey.export({ format: 'pem', type: 'pkcs8' }).toString(),
    };
}

/**
 * Publishes test agents' profiles (the JSON document carrying their public signing keys) through
 * the shop's own `public/` directory, so the web container fetches them from `localhost`, the
 * only plain-http host the SDK's URL-safety rules admit, and only in development mode.
 *
 * Never falls back to the shop's own profile: an agent that is the shop proves nothing.
 */
export const test = base.extend<NonNullable<unknown>, UcpAgentProfileHostTypes>({
    UcpAgentProfileHost: [
        async ({}, use, workerInfo) => {
            const directory = path.join(resolveShopwareDir(), 'public', AGENT_PROFILE_DIRECTORY);
            const baseUrl = agentProfileBaseUrl();
            const version = readUcpProtocolVersion();
            const written: string[] = [];

            fs.mkdirSync(directory, { recursive: true });
            fs.accessSync(directory, fs.constants.W_OK);

            const publish: UcpAgentProfileHost['publish'] = async ({ kid, label, capabilities } = {}) => {
                const agentKid = kid ?? `acceptance-w${workerInfo.parallelIndex}-${crypto.randomUUID().slice(0, 8)}`;
                const agentLabel = label ?? 'shopware-acceptance-agent';
                const { publicJwk, privateKeyPem } = generateSigningKey(agentKid);
                const profile = {
                    ucp: {
                        version,
                        services: {},
                        capabilities: capabilities ?? specShoppingCapabilities(version),
                        payment_handlers: {},
                    },
                    signing_keys: [publicJwk],
                };
                const file = path.join(directory, `${agentKid}.json`);
                const profileUrl = new URL(`${AGENT_PROFILE_DIRECTORY}/${agentKid}.json`, baseUrl).toString();

                fs.mkdirSync(directory, { recursive: true });
                fs.writeFileSync(file, JSON.stringify(profile, null, 2));
                written.push(file);

                await assertServedByTheShop(agentKid, profileUrl);

                return {
                    kid: agentKid,
                    label: agentLabel,
                    profileUrl,
                    agentHeader: `${agentLabel}; profile="${profileUrl}"`,
                    publicJwk,
                    privateKeyPem,
                    profile,
                };
            };

            await use({ host: baseUrl.hostname, directory, publish });

            // Only this worker's files. The directory is shared by every worker, and one that
            // finishes first would delete it under another's write.
            for (const file of written) {
                fs.rmSync(file, { force: true });
            }
        },
        { scope: 'worker' },
    ],
});

/**
 * The file has to reach the shop's web server through the bind mount. Checked over `APP_URL`,
 * the same document root the web container serves on `localhost`.
 */
async function assertServedByTheShop(kid: string, profileUrl: string): Promise<void> {
    const appUrl = (process.env.APP_URL ?? '').replace(/\/+$/, '') + '/';
    const publicUrl = new URL(`${AGENT_PROFILE_DIRECTORY}/${kid}.json`, appUrl).toString();
    const context = await request.newContext({ ignoreHTTPSErrors: true });

    try {
        const response = await context.get(publicUrl);
        if (!response.ok()) {
            throw new Error(
                `The agent profile written for the shop is not served: GET ${publicUrl} answered ${response.status()}. `
                + `The web container must serve <SHOPWARE_DIR>/public through its bind mount, so that ${profileUrl} resolves inside it.`,
            );
        }
    }
    finally {
        await context.dispose();
    }
}
