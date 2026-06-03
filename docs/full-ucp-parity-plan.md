# Full UCP Parity Plan

## Summary

The plugin targets UCP parity with the Shopware 6.7 UCP work while keeping the admin UI simpler than the original PR. The implementation is split across three codebases:

- Shopware 6.7/trunk core adds the Store API MCP foundation on branch `codex/store-api-mcp-endpoint`.
- `../ucp-php-sdk` advertises multiple transports and supports endpoint overrides.
- `SwagAgenticCommerce` exposes only the transports and capabilities that work on the current Shopware line.

## Support Matrix

| Transport | 6.5 | 6.6 | 6.7/trunk |
| --- | --- | --- | --- |
| REST | enabled | enabled | enabled |
| A2A | configurable | configurable | configurable |
| Embedded | configurable | configurable | configurable |
| MCP | hidden from runtime, disabled in admin | hidden from runtime, disabled in admin | enabled only when core Store API MCP exists |

UCP advertises the buyer-facing MCP endpoint as `/ucp/mcp`. On trunk, the
plugin proxies that route to the core Store API MCP endpoint `/store-api/_mcp`
and injects the sales-channel access key server-side. The access key must never
be exposed in the UCP profile or required from the MCP client. The admin MCP
endpoint `/api/_mcp` remains for merchant/admin automation and is not used for
buyer-facing UCP.

OAuth identity linking is implemented as an optional plugin-backed capability:

- The SDK routes stay registered so clients receive explicit UCP responses instead of framework `404`/`500` failures.
- The plugin registers a Shopware-backed identity-linking adapter and advertises it only when `identity_linking` is enabled for the sales channel.
- Authorization Code + PKCE S256 is supported. Authorization requires a logged-in Shopware customer context token so anonymous requests cannot mint identity-linked tokens.
- Access and refresh tokens are stored sales-channel scoped in plugin tables.

Payment tokenization remains extension-ready but not bundled as a shipped tokenizer:

- The plugin registers the tokenization capability wrapper and a non-tokenizing Shopware invoice payment-handler descriptor.
- `/ucp/v1/tokenize` must return `501` until at least one real payment handler supports UCP tokenization and the capability is enabled.
- `payment_handlers` must stay an empty object in `/.well-known/ucp` unless tokenization is enabled and a real tokenizing handler is registered.
- Store API customer login/context-token APIs and Shopware checkout payment tokens are not sufficient substitutes. They do not provide the UCP identity-linking consent model or a reusable payment-tokenization contract.
- Implementation TODO and example PSP handler shape live in
  [docs/payment-tokenization-handler.md](payment-tokenization-handler.md).

## Implementation Decisions

- Keep REST/A2A/embedded in the plugin/SDK transport surface.
- Ship a plugin-owned `/ucp/mcp` discovery endpoint that delegates to the 6.7
  core Store API MCP endpoint without leaking the sales-channel access key.
- Register UCP MCP tools into the Store API MCP registry once the core PR is available.
- Keep all transports behind the same capability layer so catalog/cart/checkout/order behavior does not fork per protocol.
- Show unsupported transports in admin as disabled with concrete reasons.
- Do not implement placeholder tokenization adapters. The identity adapter is real and customer-context backed; tokenization still requires a PSP-backed handler.

## Version Strategy

- Keep one administration implementation and drive lane differences from admin API metadata such as `supportsStoreApiMcp`.
- Keep one PHP configuration/runtime model and filter capabilities/transports at runtime through compatibility services.
- Keep Shopware-version conditionals in small compatibility seams, scripts, or gateway branches only when the platform API really differs.
- Do not copy complete admin pages, controllers, or transport handlers per Shopware version. A duplicated lane implementation is a regression risk and must be replaced by shared code plus explicit feature detection.
- Test the same browser validator against every admin lane/build mode so version drift is caught by coverage, not by duplicated code.

## Demo Data And Validation

Import demo data before implementation validation:

- 6.5: `bun run generate --instance=65 --name=music --domain=music-65`, storefront `http://music-65.localhost:8102`
- 6.6: `bun run generate --instance=66 --name=music --domain=music-66`, storefront `http://music-66.localhost:8101`
- 6.7/trunk: `bun run generate --instance=trunk --name=music --domain=music-trunk`, storefront `http://music-trunk.localhost:8100`

`--name=music` must stay stable so the catalog generator reuses the pre-generated `music` template. `--domain` is the lane-specific storefront subdomain override and prevents all three local shops from competing for `music.localhost`.

Use `bin/validate-ucp-store.sh <base-url>` for basic profile validation.
Use `bin/validate-ucp-store.sh <base-url> <profile-url> extended` to also
exercise A2A `catalog.search`/`cart.create`, embedded cart headers and bridge
markup, and trunk MCP `tools/list` when `UCP_STORE_API_ACCESS_KEY` is provided.
Use `bin/validate-ucp-store.sh <base-url> <profile-url> conformance` with
`UCP_CONFORMANCE_DIR` when the official UCP conformance suite is checked out.

The validation script also asserts the default shipped optional capability decision:

- `payment_handlers` is an empty object in the UCP profile.
- `/.well-known/oauth-authorization-server` returns `501` until
  `identity_linking` is enabled for the sales channel.
- With `identity_linking` enabled, OAuth metadata returns `200` and authorize
  requires a logged-in Shopware customer context token.
- `/ucp/v1/tokenize` returns `501`.

Validated local profile matrix:

- 6.5: `bin/validate-ucp-store.sh http://music-65.localhost:8102` returns REST/A2A/embedded.
- 6.6: `bin/validate-ucp-store.sh http://music-66.localhost:8101` returns REST/A2A/embedded.
- 6.7/trunk: `bin/validate-ucp-store.sh http://music-trunk.localhost:8100` returns REST/MCP/A2A/embedded when the Store API MCP core branch is present.

For trunk MCP validation, initialize `/ucp/mcp`, then call `tools/list`. The
plugin resolves the current sales-channel access key internally before
delegating to `/store-api/_mcp`. The expected UCP tool names cover the shopping
operation matrix: `shopware-ucp-catalog-search`,
`shopware-ucp-catalog-lookup`, cart create/get/update/cancel, discount apply,
checkout create/get/update/complete/cancel, and order get.

## Admin QA

Run the reusable browser validator for each supported lane/build mode:

- 6.5 webpack: `BASE_URL=http://sw65.localhost:8088 npm run qa:admin -- --lane 6.5-webpack`
- 6.6 webpack: `BASE_URL=http://sw66.localhost:8088 npm run qa:admin -- --lane 6.6-webpack`
- 6.6 vite: `BASE_URL=http://sw66.localhost:8088 npm run qa:admin -- --lane 6.6-vite`
- 6.7/trunk vite: `BASE_URL=http://trunk.localhost:8088 npm run qa:admin -- --lane trunk-vite`

The CI admin matrix runs this validator with `CI_ADMIN_BROWSER_VALIDATE=1`.
It logs in, opens the UCP overview/detail screens, validates authenticated admin
API save/profile/key operations, checks lane-aware profile-preview transports,
fails on UCP console errors, writes screenshots, and restores the original
sales-channel config.

Required browser assertions:

- 6.5 admin: MCP disabled, REST/A2A/embedded visible.
- 6.6 admin: MCP disabled, REST/A2A/embedded visible.
- 6.7/trunk admin: MCP enabled only when the core Store API MCP route exists;
  the profile advertises `/ucp/mcp`.
- Profile preview: effective transports match the runtime matrix.
- Sales-channel shortcut: opens the UCP detail page for the selected sales channel.

Store screenshots under `var/qa/admin-screenshots/{lane}/{build-mode}/` for CI
and under `var/qa-screenshots/` for ad-hoc local captures.

Current local screenshot artifacts are stored in `var/qa-screenshots/`:

- `admin-ucp-trunk.png` and `admin-ucp-trunk-security.png`
- `admin-ucp-66.png` and `admin-ucp-66-security.png`
- `admin-ucp-65.png` and `admin-ucp-65-security.png`

The top screenshots verify the lane transport summary. The security screenshots verify the split allowlist UX: remote profile hosts, agent/webhook hosts, embedded allowed origins, and embedded frame ancestors.

## Remaining Runtime Gaps

- Payment tokenization stays hidden/unsupported by default until a real
  PSP-backed tokenizing payment handler is registered and enabled per sales
  channel. See
  [docs/payment-tokenization-handler.md](payment-tokenization-handler.md)
  for the required service contract and validation checklist. Identity linking
  is implemented but remains opt-in per sales channel.
- Embedded now renders plugin-owned cart/checkout bridge pages with CSP,
  allowed-origin validation, and `postMessage` ready/state messages. Follow-up
  UX work should focus on visual polish and deeper storefront theme integration,
  not transport correctness.
- MCP and A2A now route the shopping operation matrix through the shared
  capability layer. Follow-up validation should target lane builds and real demo
  storefront data rather than adding protocol-specific business logic.

## QA-Only Surfaces

Smoke-only runtime surfaces must stay out of the normal shipped contract:

- Test webhook capture is available only when `SWAG_AGENTIC_COMMERCE_TEST_CAPTURE=1` and the app is not running in `prod`.
- The smoke catalog seeder is hidden and available only when `SWAG_AGENTIC_COMMERCE_SMOKE_SEED=1` and the app is not running in `prod`.
- `bin/ci-smoke.sh` sets both flags explicitly for local/CI QA.
- Package exports exclude tests, CI smoke scripts, local QA screenshots, and repository-only tooling via `.gitattributes`.
