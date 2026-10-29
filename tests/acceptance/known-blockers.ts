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
        description: 'SDK verification requires created, which the spec makes optional, so a spec-conformant signature without it is rejected under the strict signature policy. The SDK\'s own signing side still covers @method and @target-uri and always adds expires and alg. Its verifier already rebuilds the base from the components the client declares, @authority and @path included.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/229',
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
        key: 'D5',
        description: 'ucp-agent and idempotency-key are read from the MCP transport\'s HTTP request headers, not from meta on each call as the spec carries them, so a spec-conformant MCP client is rejected with "$.headers.idempotency-key is required". The tool names and the structured object payload were fixed in PR #224.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/187',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D9',
        description: 'Below Shopware 6.7.12.0 the plugin\'s fallback controller serves the discovery files and answers 404 for all of them once UCP is switched off, although the files do not depend on UCP.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/275',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D10',
        description: 'On Shopware 6.7.12.x core looks discovery files up case-sensitively and ships only agents.md, and the plugin registers no fallback route there, so /AGENTS.md answers 404.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/307',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D11',
        description: 'Below Shopware 6.7.10.0 the Validate action builds the product export without its provider, so AbstractProviderValidator skips every agentic validator and Validate reports no errors for a feed whose export fails.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/292',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D12',
        description: 'A feed channel\'s Save with an empty required provider setting is inconsistent. On 6.5 the first click does nothing visible: validateAgenticCommerceExportConfig() adds the error as a new key, which Vue 2 does not track, so the field is not marked. On a new channel (6.5 and 6.6) the second click saves anyway, because the plugin\'s onSave() override returns early inside the create page\'s $super chain.',
        owner: 'alexdumea',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/309',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D13',
        description: 'Before the Meteor select, cancelling the "Apply template" modal on a saved feed channel leaves the cancelled template in the select: onTemplateModalClose() restores the value templateName already has, so the select is not redrawn.',
        owner: 'alexdumea',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/310',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D14',
        description: 'Under the signature policy log the SDK accepts a request whose signature fails, as the policy intends, but writes no log entry about the failure, so log behaves exactly like off.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/218',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D15',
        description: 'The SDK answers a missing signature and a body that does not match its Content-Digest with 401 signature_invalid, an unfetchable agent profile with agent_profile_* codes, a schema-violating profile with invalid_request and a profile host outside the allowlist with 401 signature_invalid, where UCP 2026-08-25 names signature_missing, 400 digest_mismatch, profile_unreachable, profile_malformed and 403 profile_not_trusted.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/219',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D16',
        description: 'The SDK replay guard refuses a repeated signed request with 401, where UCP 2026-08-25 handles replays through the idempotency key and answers with the cached response.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/220',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D17',
        description: 'A sales channel with UCP switched off answers its shopping routes with 501 capability_unsupported, and checkout complete with 422, where a path that the channel does not serve answers 404. Its /.well-known/ucp answers 200 with a profile that advertises no services or capabilities.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/314',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D18',
        description: 'The SDK matches an Idempotency-Key across all agents and operations, where UCP 2026-08-25 scopes it per client and operation type. A second agent reusing a key gets the first agent\'s stored response, and an agent reusing its key for another operation is refused with 409 idempotency_conflict.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/223',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D19',
        description: 'An unknown discount code on a checkout is reported with Shopware\'s cart error, type error and code promotion-not-found, where the UCP discount extension names a warning with discount_code_invalid.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/318',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D20',
        description: 'The SDK reads no discounts on cart create and update, so codes sent with a cart are neither applied nor reported as rejected, although the profile advertises the discount capability for the cart.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/224',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D21',
        description: 'A checkout with line items and a buyer but no shipping address reports ready_for_complete without messages, where UCP 2026-08-25 answers incomplete while information is missing. Completing it is refused with 422.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/319',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D22',
        description: 'Cancelling a checkout answers 200 canceled also when it is completed or already cancelled, where UCP 2026-08-25 asks for an error. A cancelled checkout is read back as incomplete, accepts updates and can be completed into an order.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/320',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D23',
        description: 'Cancelling a cart answers with the emptied cart, where UCP 2026-08-25 requires the state before deletion. Get and update on the cancelled cart id answer 200, where UCP recommends not_found.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/321',
        reviewBy: '2026-12-31',
    },
];
