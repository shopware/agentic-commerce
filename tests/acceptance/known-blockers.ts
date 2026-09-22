/**
 * A spec blocked by one of these asserts spec-correct behaviour, carries the `@UcpKnownBlocked` tag
 * and runs in the non-gating project. Entries are enforced by `tests/Setup/known-blockers.spec.ts`.
 */
export interface KnownBlocker {
    /** Stable key, referenced by the `@UcpKnownBlocked` spec that it blocks. */
    key: string
    description: string
    owner: string
    issueUrl: string
    /** ISO date (YYYY-MM-DD). Once past, the Setup guard fails until the entry is renewed or removed. */
    reviewBy: string
}

export const knownBlockers: KnownBlocker[] = [
    {
        key: 'D1',
        description: 'The SDK signs and verifies over @method, @target-uri and content-digest and rejects a signature without created or expires. The spec covers @method, @authority and @path, makes created optional and has no expires, so a spec-conformant agent is rejected under the strict signature policy.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/187',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D2',
        description: 'The profile advertises an empty payment_handlers map, yet checkout.complete requires a payment instrument bound to a handler, so no spec agent can complete a purchase.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/105',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D3',
        description: 'The A2A and embedded transports run no signature, idempotency or UCP-Agent check; A2aController builds a bare RequestContext without a platform profile.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/pull/185',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D4',
        description: 'An unknown cart id answers HTTP 200 with an empty cart carrying the requested id instead of not_found.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/187',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D5',
        description: 'ucp-agent and idempotency-key are read from the MCP transport\'s HTTP request headers, not from meta on each call as the spec carries them, so a spec-conformant MCP client is rejected with "$.headers.idempotency-key is required". The tool names and the typed array payload were fixed in PR #224.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/187',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D6',
        description: 'On Shopware 6.5 a POST /ucp/v1/catalog/search with the canonical query body was observed to fail with "Parameter \'search\' is missing". Needs confirming on the 6.5 lane.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/187',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D7',
        description: 'The per-channel remoteProfileAllowlist reaches the request-context check but not the profile fetcher, whose UrlSafetyValidator is built from ucp_sdk.allowed_profile_hosts and never set, so every remote profile fetch is rejected.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/pull/150',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D8',
        description: 'A sales channel domain with a path prefix does not resolve to its own channel: core\'s RequestTransformer strips the prefix from the request URI before the SDK controllers build their HttpRequest, and SalesChannelDomainResolver resolves from that URI alone. /.well-known/ucp under a prefixed domain serves the host\'s root channel, or the global configuration when the host has no root domain.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/187',
        reviewBy: '2026-12-31',
    },
];
