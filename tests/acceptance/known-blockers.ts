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
        description: 'SDK verification requires created, which the spec makes optional, so a spec-conformant signature without it is rejected under the strict signature policy. Its verifier already rebuilds the base from the components the client declares, @authority and @path included.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/229',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D2',
        description: 'The profile advertises an empty payment_handlers map, so an agent has no handler to pay with. Completion places the order on the sales channel\'s default payment method without processing a payment.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/279',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D3',
        description: 'The A2A transport runs no signature check, so the strict signature policy accepts an unsigned A2A request. A2aController builds a bare RequestContext without a platform profile.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/206',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D5',
        description: 'The MCP tools read the request from payload, where the MCP binding of UCP 2026-08-25 sends it in checkout or cart with an id and meta, so a spec-conformant create_checkout is refused with "$.line_items is required". The idempotency key is read only from the HTTP request, not from meta. tools/list offers no get_product.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/329',
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
    {
        key: 'D24',
        description: 'Completing a checkout with an instrument whose handler the profile does not advertise answers completed and places an unpaid order on the sales channel\'s default payment method, where it should be refused with payment_failed.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/105',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D25',
        description: 'Completing a checkout with no payment instrument or with several answers completed and places an order, where UCP 2026-08-25 requires exactly one instrument and a payment_failed error otherwise.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/225',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D26',
        description: 'The SDK answers an unknown cart id with 404, where the cart REST binding of UCP 2026-08-25 returns not found as a business outcome with 200 and a not_found message.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/230',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D27',
        description: 'The SDK serves JSON-RPC methods named after the UCP operations on /ucp/a2a and answers message/send with -32601, where the A2A binding of UCP 2026-08-25 carries the checkout in an A2A message. The profile names /ucp/a2a as the a2a endpoint instead of the agent card, and the agent card lists no UCP extension.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/239',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D35',
        description: 'UcpMcpProxyController answers the errors it raises itself, a refused signature included, with the REST error body, where UCP 2026-08-25 returns protocol errors on MCP as a JSON-RPC error with code -32000.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/339',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D28',
        description: 'The SDK signs order webhooks over @method, @target-uri and content-digest and adds alg, where UCP 2026-08-25 signs them by REST request signing over @method, @authority, @path, ucp-agent, content-digest and content-type without alg. A platform that verifies by the UCP routine treats the webhook as unsigned.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/240',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D29',
        description: 'The SDK derives Webhook-Id from the event name and the order id, so every order.updated webhook of an order repeats the first one\'s id, where UCP 2026-08-25 asks for a unique event identifier in the format uuid.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/241',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D30',
        description: 'On a sales channel without a profile domain, the order.updated webhook carries no UCP-Agent header and an order permalink_url without a host, because OrderStateSubscriber passes an empty base URI.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/332',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D31',
        description: 'Order webhooks go only to the merchant\'s webhook URL override, never to the webhook_url that the agent publishes for the order capability. Their body is {event, order_id, order}, where UCP 2026-08-25 sends the order itself.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/280',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D32',
        description: 'The SDK serves the REST shopping routes also when rest is not among the enabled transports, so a channel with only a2a still creates carts over REST. Only the A2A and embedded controllers check their transport.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/242',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D33',
        description: 'With embedded origins configured, EmbeddedResponseListener answers an OPTIONS preflight to the embedded routes with 204 also when the embedded transport is switched off, where the GET to the same route answers 404.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/336',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D34',
        description: 'A sales channel with MCP switched off answers /ucp/mcp with 403 "UCP MCP is not available for this host", where /ucp/a2a answers 404 while A2A is switched off.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/337',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D36',
        description: 'The SDK answers checkout operations with an empty ucp.services, also when the embedded transport is enabled, where UCP 2026-08-25 requires an embedded service binding with config.delegate in the checkout response for a checkout that can be embedded.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/243',
        reviewBy: '2026-12-31',
    },
    {
        key: 'D37',
        description: 'A checkout carries no continue_url unless the merchant configures a template, and the embedded page sends ucp.embedded.ready and ucp.embedded.state, where UCP 2026-08-25 loads the embedded checkout from continue_url with ec_version and starts it with the JSON-RPC request ec.ready.',
        owner: 'dgrothaus-sw',
        issueUrl: 'https://github.com/shopware/agentic-commerce/issues/281',
        reviewBy: '2026-12-31',
    },
];
