# Manual Testing Guide

This document describes how a human tester validates `SwagAgenticCommerce`
across the supported Shopware lanes.

Before testing, also read
[docs/shopware-version-differences.md](docs/shopware-version-differences.md).
That file is the memory for lane-specific traps.

## Scope

Current manual scope:

- Composer installation and plugin activation
- administration build validation
- administration browser validation
- storefront build validation
- storefront browser validation
- UCP profile, transport, and REST flow validation
- A2A endpoint validation through the shared UCP capability layer
- embedded cart/checkout bridge rendering, security headers, and postMessage events
- Store API MCP shopping-tool validation on trunk when the core branch is present
- signed webhook validation
- version-specific behavior on `6.5.x`, `6.6.x`, and `trunk`

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
repositories against the synced container paths and require the packages:

```bash
composer config repositories.swag-agentic-commerce '{"type":"path","url":"custom/plugins/SwagAgenticCommerce","options":{"symlink":true}}'
composer config repositories.ucp-sdk-core '{"type":"path","url":"custom/ucp-php-sdk/packages/core","options":{"symlink":true,"versions":{"shopware/ucp-php-sdk-core":"0.0.1-alpha1"}}}'
composer config repositories.ucp-sdk-symfony '{"type":"path","url":"custom/ucp-php-sdk/packages/symfony-bundle","options":{"symlink":true,"versions":{"ucp-php-sdk/symfony-bundle":"0.0.1-alpha1"}}}'
composer require shopware/agentic-commerce:@dev shopware/ucp-php-sdk-core:0.0.1-alpha1 ucp-php-sdk/symfony-bundle:0.0.1-alpha1 --with-all-dependencies
bin/console plugin:refresh
bin/console plugin:install --activate SwagAgenticCommerce
```

The Composer `symlink` option is container-local package behavior. It is not
the old host-plugin-symlink workflow.

## Recommended Test Order

1. Run `ensure-lane-sync` for the lane.
2. Confirm `sync-status` is healthy.
3. Bootstrap or update the plugin installation.
4. Run repo-local QA in the plugin repo.
5. Build the administration.
6. Validate the administration UI in browser.
7. Build the storefront.
8. Validate the storefront UI in browser.
9. Validate the live UCP transport profile and core shopping flow.

## Repo-Local QA

Run these in the plugin repo:

```bash
composer ci
composer test
composer test:integration
```

Expected result: all commands pass.

## Lane Smoke

Use the existing smoke runner from the plugin repo:

```bash
bin/ci-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_65_ROOT"
bin/ci-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_66_ROOT"
bin/ci-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT"
```

Expected result:

- plugin is active
- `/.well-known/ucp` responds with `200`
- lane-aware transports are exposed: REST/A2A/embedded everywhere, MCP only when Store API MCP exists
- OAuth identity linking is hidden/`501` by default and works only when the
  sales channel explicitly enables `identity_linking`; payment tokenization
  returns `501` until a real tokenizing payment handler exists
- catalog, cart, checkout, order, and webhook smoke complete

## Administration Build Validation

Use lane-local builds or the smoke script.

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

Build success is not enough. Browser validation is required on every lane.
The CI admin matrix runs the browser validator automatically when
`CI_ADMIN_BROWSER_VALIDATE=1` is set.

Install the local browser QA dependency before running it manually:

```bash
npm install --no-audit --no-fund
npx playwright install chromium
```

Then run the reusable browser validator against each lane:

```bash
BASE_URL=http://sw65.localhost:8088 npm run qa:admin -- --lane 6.5-webpack
BASE_URL=http://sw66.localhost:8088 npm run qa:admin -- --lane 6.6-webpack
BASE_URL=http://sw66.localhost:8088 npm run qa:admin -- --lane 6.6-vite
BASE_URL=http://trunk.localhost:8088 npm run qa:admin -- --lane trunk-vite
```

The validator logs into the administration, opens the UCP overview/detail
screens, saves a lane-aware config through the authenticated admin API, verifies
profile-preview transports, creates/retires/deletes a signing key, fails on UCP
console errors, captures screenshots, and restores the original config.

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

Validate the UCP module:

- settings page shows the UCP entry
- UCP entry appears in the expected settings group for the lane
- direct route opens: `#/sw/settings/ucp/index`
- overview renders real sales channels
- detail route opens from `Configure UCP`
- sales-channel shortcut appears after `API access`
- native sales-channel settings remain visible
- save works
- key create, retire, and delete actions respond
- profile preview renders
- profile preview shows REST/A2A/embedded on 6.5/6.6 and REST/A2A/embedded/MCP on trunk only when the Store API MCP core route exists
- trunk profile advertises MCP at `/ucp/mcp`; the plugin proxies internally to `/store-api/_mcp` without exposing the sales-channel access key
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
- homepage and `/checkout/cart` render after the build

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

If demo data exists, open a product detail page and verify add-to-cart. If the
database is empty, validate the storefront shell and seed catalog data before
commerce flow testing.

## Manual UCP REST Validation

Use each lane base URL in the examples below.

### Public Profile

```bash
curl -s http://sw65.localhost:8088/.well-known/ucp | jq .
```

Expected result:

- only enabled and implemented capabilities are advertised
- `payment_handlers` is an empty object unless a real tokenizing payment handler
  is installed and `payment_tokenization` is enabled
- 6.5/6.6 advertise REST/A2A/embedded when enabled, but never MCP
- 6.7/trunk advertises MCP only when the Store API MCP core endpoint is available

### Validator Script

Run basic validation on each demo storefront:

```bash
bin/validate-ucp-store.sh http://music-65.localhost:8102
bin/validate-ucp-store.sh http://music-66.localhost:8101
bin/validate-ucp-store.sh http://music-trunk.localhost:8100
```

Run extended validation after demo data exists:

```bash
bin/validate-ucp-store.sh http://music-65.localhost:8102 '' extended
bin/validate-ucp-store.sh http://music-66.localhost:8101 '' extended
bin/validate-ucp-store.sh http://music-trunk.localhost:8100 '' extended
```

Expected result:

- A2A `catalog.search` and sample `cart.create` work when A2A is advertised
- embedded cart page returns `frame-ancestors`, CORS origin, and bridge markup
- trunk MCP `tools/list` is skipped unless `UCP_STORE_API_ACCESS_KEY` is set
- when MCP auth is configured, `tools/list` contains catalog, cart, discount,
  checkout, and order UCP tools

### Optional OAuth And Tokenization Endpoints

OAuth metadata with `identity_linking` disabled:

```bash
curl -i http://sw65.localhost:8088/.well-known/oauth-authorization-server
```

OAuth metadata with `identity_linking` enabled for the sales channel:

```bash
curl -i http://sw65.localhost:8088/.well-known/oauth-authorization-server
```

OAuth authorize without a logged-in Store API/customer context token:

```bash
curl -i 'http://sw65.localhost:8088/ucp/v1/oauth/authorize?response_type=code&client_id=https%3A%2F%2Fagent.example%2Fprofile.json&redirect_uri=https%3A%2F%2Fagent.example%2Fcallback&scope=dev.ucp.shopping.cart%3Amanage&code_challenge=test&code_challenge_method=S256'
```

Tokenization:

```bash
curl -i -X POST http://sw65.localhost:8088/ucp/v1/tokenize \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: manual-test-tokenize-1' \
  -d '{"type":"tokenized","handler_id":"test","credential":{}}'
```

Expected result:

- endpoints exist
- OAuth metadata returns `501` while `identity_linking` is disabled
- OAuth metadata returns `200` after `identity_linking` is enabled
- OAuth authorize returns a controlled `400` when no logged-in customer context
  token is provided
- tokenization returns `501` until a real tokenizing payment handler exists
- neither returns `404` or framework `500`
- if a project installs real tokenization extensions, repeat this check with
  `payment_tokenization` enabled and verify the profile advertises only the
  installed implementation. Use
  [docs/payment-tokenization-handler.md](docs/payment-tokenization-handler.md)
  as the implementer checklist.

### Seed Catalog If Needed

```bash
bin/console swag-agentic-commerce:seed-smoke-catalog --sales-channel-id=<sales-channel-id>
bin/console sales-channel:list
```

### Catalog Search

```bash
curl -s -X POST http://sw65.localhost:8088/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: manual-test-search-1' \
  -d '{"query":"music","limit":3}' | jq .
```

Expected result: at least one product with stable id, title, and price data.

### Catalog Lookup

```bash
curl -s -X POST http://sw65.localhost:8088/ucp/v1/catalog/lookup \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: manual-test-lookup-1' \
  -d '{"ids":["<product-id>"]}' | jq .
```

Expected result: exactly the requested product is returned.

### Cart And Checkout

Create a cart with a real product id, then create and complete a checkout.
Completion must include a real shipping address. Placeholder addresses are
intentionally rejected.

Example completion payload:

```json
{
  "buyer": {
    "email": "manual-test@example.com",
    "firstName": "Manual",
    "lastName": "Tester",
    "phoneNumber": "+49123456789",
    "shippingAddress": {
      "street": "Test Street 1",
      "zipcode": "12345",
      "city": "Berlin",
      "countryCode": "DE"
    }
  }
}
```

Expected result:

- checkout completion creates a real Shopware order
- order read returns the created order

## Signed Webhook Validation

For local manual validation, use the test-only capture endpoint:

```text
http://sw65.localhost:8088/_action/swag-agentic-commerce/test/webhooks
```

Then complete a checkout and read captured webhooks:

```bash
curl -s http://sw65.localhost:8088/_action/swag-agentic-commerce/test/webhooks | jq .
```

Expected result:

- captured payload is present
- payload contains the created order
- signature headers are present

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

- plugin installs through Composer
- plugin activates
- admin build for the lane succeeds
- UCP administration module renders in browser
- storefront build for the lane succeeds
- storefront UI renders in browser
- settings save succeeds
- signing key actions work
- `/.well-known/ucp` returns the expected lane-aware transport profile
- OAuth/tokenization optional endpoints match the enabled capability state:
  OAuth is `501` when disabled and works when `identity_linking` is enabled;
  tokenization remains `501` until a real tokenizing payment handler is present
- catalog search and lookup work
- cart and checkout flow work
- order read works
- signed webhook delivery works
