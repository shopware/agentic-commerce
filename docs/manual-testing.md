# Manual Testing Guide

This document describes how a human tester validates `SwagAgenticCommerce`
across the supported Shopware lanes.

Automated coverage (PHPUnit, `bin/ci-smoke.sh`, e2e Playwright) runs in CI;
this document covers only what automation cannot. The REST happy-path,
profile/transport advertising, OAuth/tokenization `501` stubs, signed-webhook
header capture, admin module rendering, and storefront shell rendering are all
exercised automatically and need no manual repro — see the pointers throughout
this guide.

Before testing, also read
[docs/shopware-version-differences.md](docs/shopware-version-differences.md).
That file is the memory for lane-specific traps.

## Scope

This guide is intentionally limited to scenarios that automation cannot
(or does not) cover. The core manual scenarios are:

- real-browser cross-origin iframe enforcement of the embedded cart
- real MCP client connectivity against `/ucp/mcp`
- real external third-party platform-profile host (SSRF allowlist)
- admin ACL/UX behavior across `ucp.viewer`, `ucp.editor`, `ucp.key_rotator`
- real payment-tokenization handler end to end
- true concurrent checkout completion (the checkout lock)
- signed / strict-signature request verification via the conformance suite

The build/runtime scaffolding (sync, install, administration and storefront
build + browser validation) also stays manual and is described below.

Still not covered as bundled shipped features: payment tokenization,
fulfillment, loyalty, and full agentic discovery/export. OAuth identity linking
is implemented but opt-in per sales channel. Payment tokenization is
extension-ready and must remain hidden/`501` unless a real tokenizing payment
handler is installed and enabled. The expected PSP implementation shape is
documented in
[docs/payment-tokenization-handler.md](docs/payment-tokenization-handler.md).

## Supported Matrix

| Lane | Shopware ref | Local URL | Admin build modes | Discovery behavior |
| --- | --- | --- | --- | --- |
| `65` | `6.5.x` | `http://sw65.localhost:8088` | `webpack` | unavailable |
| `66` | `6.6.x` | `http://sw66.localhost:8088` | `webpack`, `vite` | unavailable |
| `trunk` | `trunk` | `http://trunk.localhost:8088` | `vite` | bridge may exist, export still out of scope |

## Preconditions

- Local checkouts exist for the plugin, SDK, and each Shopware lane. Set the
  following environment variables to point at them (see `README.md` §
  *Local development* for the full list and suggested shell profile setup):

  | Variable | Points at |
  | --- | --- |
  | `AGENTIC_COMMERCE_PLUGIN_ROOT` | this repository |
  | `AGENTIC_COMMERCE_SDK_ROOT` | `ucp-php-sdk` checkout |
  | `AGENTIC_COMMERCE_SHOPWARE_65_ROOT` | `shopware` `6.5.x` checkout |
  | `AGENTIC_COMMERCE_SHOPWARE_66_ROOT` | `shopware` `6.6.x` checkout |
  | `AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT` | `shopware` `trunk` checkout |

- Docker or Podman is available.
- Mutagen is available.
- `jq` and `curl` are available.

Do not use lane switching, `docker cp`, or manual rsync for this workflow.
Shopware, the plugin, and the SDK are synced per lane through persistent
two-way Mutagen sessions.

## Sync And Startup

Start or repair one lane:

```bash
~/scripts/agentic-commerce/ensure-lane-sync 65
~/scripts/agentic-commerce/ensure-lane-sync 66
~/scripts/agentic-commerce/ensure-lane-sync trunk
```

Check all lanes:

```bash
~/scripts/agentic-commerce/sync-status
```

Expected result:

- every Shopware, plugin, and SDK session is `Two Way Resolved`
- every session is watching for changes
- plugin and SDK files exist inside each lane container

Generated files are created inside the lane containers. Some Shopware checkout
changes can sync back and become dirty after builds, but UCP admin bundles are
lane-local/disposable:

- `var/plugins.json` is generated per lane by Shopware and must not be copied
  between lanes.
- `src/Resources/public/` and `public/bundles/swagagenticcommerce/` are ignored
  by the lane sync helper and cleaned by `bin/ci-admin-smoke.sh` before each
  admin build.
- UCP sales-channel config is stored in `swag_agentic_commerce_ucp_config`.
  Legacy `SystemConfig` values are compatibility input only and should not be
  copied or edited as the source of truth.

## Running Commands In A Lane

Use the lane helpers instead of raw Compose commands:

```bash
~/scripts/agentic-commerce/lane-exec 65 php -v
~/scripts/agentic-commerce/lane-exec 66 composer run-script build:js:admin
~/scripts/agentic-commerce/lane-shell trunk
```

The Compose service is named `web` in every lane, but the helpers select the
correct project/container.

## Install The Plugin And SDK

The normal local path repository setup is handled by:

```bash
~/scripts/agentic-commerce/bootstrap-lane 65
~/scripts/agentic-commerce/bootstrap-lane 66
~/scripts/agentic-commerce/bootstrap-lane trunk
```

If you must install manually inside a lane container, configure Composer path
repositories against the synced container paths and require only the plugin:

```bash
composer config repositories.swag-agentic-commerce '{"type":"path","url":"custom/plugins/SwagAgenticCommerce","options":{"symlink":true}}'
composer config repositories.ucp-sdk-core '{"type":"path","url":"custom/ucp-php-sdk/packages/core","options":{"symlink":true,"versions":{"shopware/ucp-php-sdk-core":"0.0.1"}}}'
composer config repositories.ucp-sdk-symfony '{"type":"path","url":"custom/ucp-php-sdk/packages/symfony-bundle","options":{"symlink":true,"versions":{"ucp-php-sdk/symfony-bundle":"0.0.1"}}}'
composer require shopware/agentic-commerce:6.6.9999999-dev --with-all-dependencies
bin/console plugin:refresh
bin/console plugin:install --activate SwagAgenticCommerce
```

Use the matching lane version when requiring the plugin manually:
`6.5.9999999-dev` for 6.5, `6.6.9999999-dev` for 6.6, and
`6.7.9999999-dev` for trunk/current 6.7.

The plugin directly requires only `ucp-php-sdk/symfony-bundle`; SDK core is
resolved transitively by that bundle. The local path repositories above are
only needed while the SDK packages are private/local.
Use stable `0.0.1` path aliases for both SDK packages. Composer does not
propagate alpha stability flags from the SDK bundle to the root Shopware
project, so alpha path aliases can make the transitive core package
unsatisfiable.

The Composer `symlink` option is container-local package behavior. It is not
the old host-plugin-symlink workflow.

## Recommended Test Order

1. Run `ensure-lane-sync` for the lane.
2. Confirm `sync-status` is healthy.
3. Bootstrap or update the plugin installation.
4. Run repo-local QA in the plugin repo.
5. Build the administration.
6. Validate the administration UI in browser (including ACL/UX, below).
7. Build the storefront.
8. Validate the storefront UI in browser.
9. Walk the manual-only scenarios that automation cannot cover (below).

## Repo-Local QA

Run these in the plugin repo:

```bash
composer ci
composer test
composer test:integration
```

Expected result: all commands pass.

## Automated REST / Profile / Webhook Coverage

The UCP REST happy path is exercised automatically by `bin/ci-smoke.sh` in the
`shopware-matrix` CI jobs on every lane, so it needs no manual repro. That
script covers, per lane:

- `GET /.well-known/ucp` profile with lane-aware transports
  (REST/A2A/embedded everywhere, MCP only when the Store API MCP endpoint
  exists), the enabled shopping capabilities, and an empty `payment_handlers`
  object
- the "MCP supported &hArr; MCP advertised" invariant (server-side
  `StoreApiMcpServerController` class check)
- fallback `/llms.txt` and `/agents.md` when core agentic files are absent
- `oauth-authorization-server` &rarr; `501` and `tokenize` &rarr; `501`
- a runtime request **without a `UCP-Agent` header** &rarr; `422`
- catalog search/lookup/product, cart create/get/update/cancel, checkout
  create/get/update/complete &rarr; a real Shopware order, and secured order read
- signed outbound webhook capture (`signature`, `signature-input`, and
  `content-digest` headers present)

You can still run the smoke runner locally if you want a fast confidence pass:

```bash
bin/ci-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_65_ROOT"
bin/ci-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_66_ROOT"
bin/ci-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT"
```

> **Runtime header note:** every `/ucp/...` runtime request must carry a
> `UCP-Agent` header (ucp-php-sdk request-time validation) or it returns `422`
> before reaching the capability. Any manual curl below that hits a runtime
> endpoint therefore includes
> `-H 'UCP-Agent: <label>; profile="<base>/.well-known/ucp"'`.

> **Known blocker:** the trunk Store API MCP endpoint ships with
> [shopware/shopware#17228](https://github.com/shopware/shopware/pull/17228).
> Until that PR is merged, `trunk` does not expose the endpoint, so the MCP
> transport is absent from `/.well-known/ucp` and MCP-specific checks skip
> automatically.

## Administration Build Validation

Use lane-local builds or the smoke script. Build success is necessary but not
sufficient — browser validation is required on every lane.

### 6.5.x

```bash
bin/ci-admin-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_65_ROOT" webpack
```

### 6.6.x

```bash
bin/ci-admin-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_66_ROOT" webpack
bin/ci-admin-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_66_ROOT" vite
```

### trunk

```bash
bin/ci-admin-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT" vite
```

Expected result:

- build exits successfully
- Shopware administration assets are built
- `SwagAgenticCommerce` administration assets exist under `public`
- `/admin` still renders after the build

The CI admin matrix runs Playwright automatically when
`CI_ADMIN_BROWSER_VALIDATE=1` is set, covering UCP module render, overview,
detail, the authenticated admin API list/detail/config/preview, and the
signing-key lifecycle. The Playwright suite is the release-confidence check for
those flows; the manual browser steps below are for exploratory UX review and
for the ACL/UX matrix that CI cannot run (CI runs as a privileged user only).

Install the local browser QA dependency before running it manually:

```bash
npm ci --no-audit --no-fund
npx playwright install chromium
```

Then run the same Playwright tests that CI uses against local always-on lanes:

```bash
BASE_URL=http://sw65.localhost:8088 SHOPWARE_REF=6.5.x ADMIN_BUILD_MODE=webpack npm run test:e2e:admin
BASE_URL=http://sw66.localhost:8088 SHOPWARE_REF=6.6.x ADMIN_BUILD_MODE=webpack npm run test:e2e:admin
BASE_URL=http://trunk.localhost:8088 SHOPWARE_REF=trunk ADMIN_BUILD_MODE=vite npm run test:e2e:admin
```

6.6 Vite is still build-validated separately. Browser validation should run
against the active 6.6 administration runtime after that build:

```bash
BASE_URL=http://sw66.localhost:8088 SHOPWARE_REF=6.6.x ADMIN_BUILD_MODE=vite npm run test:e2e:admin
```

`npm run test:e2e:local:all` intentionally uses one current 6.6 runtime. Do
not treat it as proof for both 6.6 build modes unless the 6.6 admin was rebuilt
between the webpack and Vite checks.

Typical local runtime once the lane is already running and built:

- admin Playwright project: about 60-120 seconds per lane
- storefront Playwright project: about 15-45 seconds per lane
- UCP API Playwright project: about 5-20 seconds per lane
- all local browser/API E2E across 6.5, 6.6, and trunk: about 4-8 minutes

Cold CI jobs remain slower because they also install Composer/NPM dependencies,
build assets, compile themes, and bootstrap Shopware.

## Administration Browser Validation

Open the lane admin:

- `http://sw65.localhost:8088/admin`
- `http://sw66.localhost:8088/admin`
- `http://trunk.localhost:8088/admin`

Default local login:

- username: `admin`
- password: `shopware`

If needed, reset inside the lane:

```bash
bin/console user:change-password admin --password 'shopware'
```

The module render, overview, detail, save, signing-key actions, and profile
preview are asserted by the Playwright admin suite. Use the manual open for
exploratory UX review:

- settings page shows the UCP entry in the expected settings group for the lane
- direct route opens: `#/sw/settings/ucp/index`
- overview renders real sales channels and the native sales-channel shortcut
- profile preview renders the lane-aware transports
- browser console has no UCP runtime error

## Storefront Build Validation

Use the storefront smoke script:

```bash
bin/ci-storefront-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_65_ROOT"
bin/ci-storefront-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_66_ROOT"
bin/ci-storefront-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT"
```

Expected result:

- storefront JavaScript builds
- active theme compiles
- built storefront assets exist under `public/theme`
- Playwright verifies homepage and `/checkout/cart` render after the build

## Storefront Browser Validation

Open:

- `http://sw65.localhost:8088/`
- `http://sw66.localhost:8088/`
- `http://trunk.localhost:8088/`

Verify:

- homepage renders without white screen or asset errors
- header, navigation, and cart shell are visible
- `/checkout/cart` renders
- after a storefront build, the live page still loads and stays interactive

Or run the browser check directly against the local lane:

```bash
BASE_URL=http://sw65.localhost:8088 SHOPWARE_REF=6.5.x npm run test:e2e:storefront
BASE_URL=http://sw66.localhost:8088 SHOPWARE_REF=6.6.x npm run test:e2e:storefront
BASE_URL=http://trunk.localhost:8088 SHOPWARE_REF=trunk npm run test:e2e:storefront
```

If demo data exists, open a product detail page and verify add-to-cart. If the
database is empty, validate the storefront shell and seed catalog data before
commerce flow testing.

## Manual-Only Scenarios

These seven scenarios are the core of this guide. CI cannot prove them — it
asserts headers, runs JSON-RPC over curl, completes a checkout once, or runs as
a single privileged user. A human must drive each one.

Seed a catalog first if the lane database is empty:

```bash
bin/console swag-agentic-commerce:seed-smoke-catalog --sales-channel-id=<sales-channel-id>
bin/console sales-channel:list
```

### 1. Real-browser cross-origin iframe enforcement

CI asserts only that the embedded cart response carries
`content-security-policy: frame-ancestors ...` and omits `x-frame-options`. It
does not run a real browser, so it cannot prove the browser actually enforces
the policy.

Steps:

- Configure `embeddedAllowedOrigins`/`embeddedFrameAncestors` for the sales
  channel to include an allowlisted parent origin.
- Build a small parent HTML page served from that **allowlisted** origin that
  embeds the embedded cart surface in an `<iframe>`. Open it in a real browser.
  Expected: the iframe renders, and the postMessage bridge round-trips
  (parent receives bridge messages, child accepts parent messages).
- Serve the same parent page from a **non-allowlisted** origin and open it.
  Expected: the browser blocks the frame via the `frame-ancestors` CSP
  directive and logs a CSP violation in the console; no bridge messages flow.

### 2. Real MCP client

CI initializes MCP via curl/JSON-RPC and skips the client-transport checks. A
real MCP client must be exercised by hand (trunk only, and only once the Store
API MCP endpoint from shopware/shopware#17228 is present so `/.well-known/ucp`
advertises the `mcp` transport).

Steps:

- Connect an actual MCP client (e.g. Claude desktop/CLI) to
  `http://trunk.localhost:8088/ucp/mcp`.
- Run `tools/list` through the real client transport.
- Invoke catalog, cart, checkout, and order tools through the client.
  Expected: the client lists the UCP shopping tools and each tool call returns
  a valid result over the real transport.

### 3. Real external third-party platform-profile host (SSRF guard)

CI uses the localhost self-profile development shortcut only, so it cannot
prove the remote-profile allowlist guards against a real external host.

Steps:

- Set `remoteProfileAllowlist` for the sales channel to a real external host
  that serves a UCP profile.
- Send a runtime request whose `UCP-Agent` profile is hosted on the
  **allowlisted** host:

  ```bash
  curl -s -X POST http://sw65.localhost:8088/ucp/v1/catalog/search \
    -H 'Content-Type: application/json' \
    -H 'Idempotency-Key: manual-ssrf-allow-1' \
    -H 'UCP-Agent: external-agent; profile="https://allowlisted.example/.well-known/ucp"' \
    -d '{"query":"music","limit":3}' | jq .
  ```

  Expected: the request is accepted and the profile is fetched.
- Repeat with a `UCP-Agent` profile hosted on a **non-allowlisted** host.
  Expected: the request is rejected by the SSRF guard (no outbound fetch to the
  disallowed host).

### 4. Admin ACL / UX

CI runs the admin suite as a privileged user only. Verify the role matrix by
logging in as users that hold exactly one of the UCP ACL roles.

- `ucp.viewer`: save and signing-key actions are disabled; a read-only banner
  is shown.
- `ucp.key_rotator`: can create, retire, and delete signing keys, but cannot
  save config.
- `ucp.editor`: can save config.

Also visually verify the signing-key management UX: the algorithm select, the
`kid` input, and the retire/delete confirmation dialogs.

### 5. Real payment-tokenization handler

CI only checks the `501` stub and the empty `payment_handlers` object. To prove
the real path, install a real tokenizing payment handler (see
[docs/payment-tokenization-handler.md](docs/payment-tokenization-handler.md)
for the implementer checklist) and enable `payment_tokenization` for the sales
channel.

Steps:

- Confirm `GET /.well-known/ucp` now advertises the installed handler under
  `payment_handlers` (no longer an empty object).
- Confirm `POST /ucp/v1/tokenize` returns a real token (not `501`):

  ```bash
  curl -i -X POST http://sw65.localhost:8088/ucp/v1/tokenize \
    -H 'Content-Type: application/json' \
    -H 'Idempotency-Key: manual-test-tokenize-1' \
    -H 'UCP-Agent: manual-tester; profile="http://sw65.localhost:8088/.well-known/ucp"' \
    -d '{"type":"tokenized","handler_id":"<installed-handler-id>","credential":{},"binding":{"checkout_id":"<checkout-id>"}}'
  ```

- Complete a checkout that uses the token. Expected: a paid Shopware order is
  created.

### 6. True concurrent checkout completion (the lock)

CI completes a checkout once (single-shot). True concurrency is inherently
flaky to automate, so verify the checkout lock by hand. Create and prepare a
checkout session, then fire two `/complete` requests for the **same** checkout
concurrently:

```bash
CHECKOUT_ID=<checkout-id>
AGENT='UCP-Agent: manual-tester; profile="http://sw65.localhost:8088/.well-known/ucp"'
for n in 1 2; do
  curl -s -X POST "http://sw65.localhost:8088/ucp/v1/checkout-sessions/${CHECKOUT_ID}/complete" \
    -H 'Content-Type: application/json' \
    -H "Idempotency-Key: manual-concurrent-complete-${n}" \
    -H "${AGENT}" \
    -d "$(jq -cn --arg id "${CHECKOUT_ID}" '{id: $id, payment: {}}')" &
done
wait
```

Expected result:

- exactly one Shopware order is created (no duplicate)
- neither request returns a framework `500`
- the second request returns the same completed result as the first

### 7. Signed / strict-signature request verification

The smoke runner sends **unsigned** requests, so it opts the lane into
log-only verification and does not assert signed-request acceptance/rejection.
Use the UCP conformance suite for signed positive/negative coverage — it signs
its requests with the simulation secret:

```bash
bin/validate-ucp-store.sh http://sw65.localhost:8088 '' conformance
bin/validate-ucp-store.sh http://sw66.localhost:8088 '' conformance
bin/validate-ucp-store.sh http://trunk.localhost:8088 '' conformance
```

> **Important nuance:** flipping `signaturePolicy=strict` and sending a request
> with **no** signature does *not* get rejected on the runtime path — the SDK
> rejects *malformed* signatures, not *absent* ones. Strict-rejection of an
> unsigned request is therefore **not** a valid manual check. Use the
> conformance suite (which actually signs requests) for signed coverage.

## Failure Report Checklist

Capture:

- lane and Shopware ref
- admin build mode
- exact command or browser path
- expected result
- actual result
- HTTP status and response body for API failures
- browser console error or server log excerpt
- `sync-status` output when file freshness is suspicious

## Minimum Acceptance Checklist

A lane passes manual validation when all are true:

- plugin installs through Composer and activates
- admin build for the lane succeeds
- UCP administration module renders in browser (exploratory)
- storefront build for the lane succeeds and the storefront UI renders
- real-browser cross-origin iframe enforcement behaves correctly: embed renders
  + bridge works on an allowlisted origin, browser blocks the frame with a CSP
  violation on a non-allowlisted origin (scenario 1)
- a real MCP client lists and invokes tools over `/ucp/mcp` on trunk when the
  endpoint is present (scenario 2)
- the remote-profile allowlist accepts an allowlisted external profile host and
  rejects a non-allowlisted one (scenario 3)
- admin ACL/UX matrix is correct for `ucp.viewer`, `ucp.editor`, and
  `ucp.key_rotator`, and the signing-key UX renders (scenario 4)
- with a real tokenizing handler installed, the profile advertises it,
  `tokenize` returns a token, and a paid order completes (scenario 5)
- concurrent `/complete` requests produce exactly one order with no duplicate
  and no `500` (scenario 6)
- the conformance suite passes for signed positive/negative coverage
  (scenario 7)
