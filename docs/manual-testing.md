# Manual Testing Guide

This document describes how a human tester validates `SwagAgenticCommerce` across the supported
Shopware lanes.

Automated coverage runs in CI: the PHPUnit unit, integration and functional suites, and the
Playwright acceptance suite in [`tests/acceptance`](../tests/acceptance/README.md). The acceptance
suite drives the product feed, the discovery files, the UCP transports with signed requests, the
embedded checkout in a real browser and the Administration tab on every lane. This document covers
only what that automation cannot.

Before testing, also read [docs/shopware-version-differences.md](shopware-version-differences.md).
That file is the memory for lane-specific traps.

## Scope

This guide is intentionally limited to scenarios that automation cannot (or does not yet) cover:

- a real third-party MCP client against `/ucp/mcp`
- exploratory use of the Administration with `ucp.viewer`, `ucp.editor` and `ucp.key_rotator`, and
  saving across the sales channel tabs
- a real payment-tokenization handler end to end

The build/runtime scaffolding (sync, install, administration and storefront builds) also stays
manual and is described below.

Still not covered as bundled shipped features: payment tokenization, fulfillment, loyalty, and full
agentic discovery/export. OAuth identity linking is implemented but opt-in per sales channel.
Payment tokenization is extension-ready and must remain hidden/`501` unless a real tokenizing
payment handler is installed and enabled. The expected PSP implementation shape is documented in
[docs/payment-tokenization-handler.md](payment-tokenization-handler.md).

## Supported Matrix

| Lane    | Shopware ref | Local URL                     | Admin build modes | Discovery behavior                          |
| ------- | ------------ | ----------------------------- | ----------------- | ------------------------------------------- |
| `65`    | `6.5.x`      | `http://sw65.localhost:8088`  | `webpack`         | unavailable                                 |
| `66`    | `6.6.x`      | `http://sw66.localhost:8088`  | `webpack`, `vite` | unavailable                                 |
| `trunk` | `trunk`      | `http://trunk.localhost:8088` | `vite`            | bridge may exist, export still out of scope |

## Preconditions

- Local checkouts exist for the plugin, SDK, and each Shopware lane. Set the following environment
  variables to point at them (see [local-development.md](local-development.md) for the full list and
  suggested shell profile setup):

  | Variable                               | Points at                   |
  | -------------------------------------- | --------------------------- |
  | `AGENTIC_COMMERCE_PLUGIN_ROOT`         | this repository             |
  | `AGENTIC_COMMERCE_SDK_ROOT`            | `ucp-php-sdk` checkout      |
  | `AGENTIC_COMMERCE_SHOPWARE_65_ROOT`    | `shopware` `6.5.x` checkout |
  | `AGENTIC_COMMERCE_SHOPWARE_66_ROOT`    | `shopware` `6.6.x` checkout |
  | `AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT` | `shopware` `trunk` checkout |

- Docker or Podman is available.
- Mutagen is available.
- `jq` and `curl` are available.

Do not use lane switching, `docker cp`, or manual rsync for this workflow. Shopware, the plugin, and
the SDK are synced per lane through persistent two-way Mutagen sessions.

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

Generated files are created inside the lane containers. Some Shopware checkout changes can sync back
and become dirty after builds, but UCP admin bundles are lane-local/disposable:

- `var/plugins.json` is generated per lane by Shopware and must not be copied between lanes.
- `src/Resources/public/` and `public/bundles/swagagenticcommerce/` are ignored by the lane sync
  helper and cleaned by `bin/ci-admin-smoke.sh` before each admin build.
- UCP sales-channel config is stored in `swag_agentic_commerce_ucp_config`. Legacy `SystemConfig`
  values are compatibility input only and should not be copied or edited as the source of truth.

## Running Commands In A Lane

Use the lane helpers instead of raw Compose commands:

```bash
~/scripts/agentic-commerce/lane-exec 65 php -v
~/scripts/agentic-commerce/lane-exec 66 composer run-script build:js:admin
~/scripts/agentic-commerce/lane-shell trunk
```

The Compose service is named `web` in every lane, but the helpers select the correct
project/container.

## Install The Plugin And SDK

Set up a lane with the helper:

```bash
~/scripts/agentic-commerce/bootstrap-lane 65
~/scripts/agentic-commerce/bootstrap-lane 66
~/scripts/agentic-commerce/bootstrap-lane trunk
```

To install by hand inside a lane container, no Composer configuration is needed:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagAgenticCommerce
```

The Shopware project already lists `custom/plugins/*` as a Composer path repository, and the
plugin's `composer.json` carries its own version, so Composer finds the plugin where it is.
`plugin:install` then runs `composer require` for the plugin, which downloads the UCP SDK from
Packagist at the exact version the plugin pins. A merchant's shop gets the SDK the same way. See
[The SDK version pin](releasing.md#the-sdk-version-pin) and
[Dependencies in a release](releasing.md#dependencies-in-a-release).

Do not add `ucp-sdk-*` path repositories or require a `-dev` version of the plugin. To test SDK
changes that are not released yet, run `bin/ci-smoke.sh` with `UCP_SDK_SOURCE=path`, see
[Which SDK a CI job resolves](releasing.md#which-sdk-a-ci-job-resolves).

## Install A Built Package

To test the package a merchant installs, rather than a lane checkout, download a built ZIP:

- For a pull request, open its `zip-artifact` check and download `SwagAgenticCommerce` from the
  run's **Artifacts**. The extension list shows it as `Agentic Commerce (PR #<number>)`.
- For a pre-release, download the ZIP attached to the GitHub release `<version> (pre-release)`. The
  extension list shows it as `Agentic Commerce (pre-release <version>)`.

Upload it under **Extensions → My extensions → Upload extension**. The shop must reach Packagist,
because Shopware installs the UCP SDK with Composer during the install. See
[Test packages on pull requests](releasing.md#test-packages-on-pull-requests) and
[Pre-releases for testing](releasing.md#pre-releases-for-testing) for how both are built.

## Recommended Test Order

1. Run `ensure-lane-sync` for the lane.
2. Confirm `sync-status` is healthy.
3. Bootstrap or update the plugin installation.
4. Run repo-local QA in the plugin repo.
5. Build the administration.
6. Review the administration UI in the browser (including ACL/UX, below).
7. Build the storefront.
8. Walk the manual-only scenarios that automation cannot cover (below).

## Repo-Local QA

Run these in the plugin repo:

```bash
composer ci
composer test
composer test:integration
composer test:functional  # requires a lane: SHOPWARE_PROJECT_DIR unset + APP_ENV=test
```

Expected result: all commands pass. `composer test:functional` boots a real test kernel and drives
UCP routes through a real Symfony browser; like core's functional tests it assumes a booted kernel,
so run it against a configured lane (not the fast-path bootstrap used by plain `composer ci`).

The acceptance suite runs against a lane as described in
[its README](../tests/acceptance/README.md#running-it).

## Administration Build Validation

Use lane-local builds or the build gate script:

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

The Agentic Commerce tab itself is covered by the `UcpAdmin` acceptance project. The browser review
below is exploratory.

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

Use the manual open for exploratory UX review:

- the Agentic Commerce tab renders on a storefront sales channel with the lane-aware transports
- Storefront and Headless channels show the UCP tab to users with `ucp.viewer`
- users with `ucp.viewer` but without `ucp.editor` see a read-only banner and disabled controls
- users with `ucp.editor` can save UCP configuration without `sales_channel.editor`
- Agentic Commerce Insights remain hidden without `ucp.viewer`, including direct navigation
- browser console has no UCP runtime error
- a Product Comparison and an Agentic Commerce channel show no UCP card, and saving their feed
  settings still succeeds
- `PUT /api/_admin/ucp/sales-channels/<feed-channel-id>/config` with `{"active": true}` answers
  `400` (`SWAG_AGENTIC_COMMERCE__UCP_SALES_CHANNEL_TYPE_NOT_SUPPORTED`), while `{"active": false}`
  is accepted
- `ucp:channels` lists no feed channel, and
  `ucp:config:set --sales-channel <feed-channel-id> --active 1` exits non-zero
- signing keys are managed with `ucp:signing-keys:{generate,list,show-public,retire,delete}` through
  `bin/console`; there is no Administration signing-key interface

## Storefront Build Validation

Use the storefront build gate script:

```bash
bin/ci-storefront-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_65_ROOT"
bin/ci-storefront-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_66_ROOT"
bin/ci-storefront-smoke.sh "$AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT"
```

Expected result:

- storefront JavaScript builds
- active theme compiles
- the homepage answers `200` and links a compiled theme stylesheet that is served

## Test Data

One command creates products to test with, and removes them again. It does not run on
`APP_ENV=prod`.

```bash
bin/console swag-agentic-commerce:test-data --sales-channel "Native en-US shop"
bin/console swag-agentic-commerce:test-data --remove
```

The command asks whether to use the product catalogue: products with photos and German texts, a 16
MB download. Press Enter for yes. `--offline` gives you simple products without photos instead.
Every run prints a seed; `--seed <n>` gives you the same products again.

Everything it creates starts with `[AC Test]`, product numbers with `SWAG-AC-TEST-`:

| Product number               | What to test with it                                       |
| ---------------------------- | ---------------------------------------------------------- |
| `SWAG-AC-TEST-PHYSICAL`      | Variants, one of them a digital download                   |
| `SWAG-AC-TEST-DIGITAL`       | A digital product with a physical variant                  |
| `SWAG-AC-TEST-COLOURS`       | Colour or flavour variants with swatches (catalogue only)  |
| `SWAG-AC-TEST-TIER-PRICES`   | Lower prices from 5 and from 10 pieces                     |
| `SWAG-AC-TEST-CUSTOM-FIELDS` | Custom fields                                              |
| `SWAG-AC-TEST-PROPERTIES`    | Properties                                                 |
| `SWAG-AC-TEST-MEMBERS-ONLY`  | Visible to logged-in customers only (needs Dynamic Access) |
| `SWAG-AC-TEST-BUNDLE`        | A bundle at 10 % off (needs Shopware Commercial)           |

You also get a category "[AC Test] Test products", the promotion codes `AC-TEST-10` (10 % off) and
`AC-TEST-SHIP` (free shipping), and, with PayPal installed, PayPal's payment methods in the sales
channel.

If the command reports `already present`, run `--remove` first. `--remove` deletes only what the
command created.

## Manual-Only Scenarios

The acceptance suite cannot prove these. A human must drive each one. Create products with
[Test Data](#test-data) first if the lane database is empty.

### 1. Real MCP client

The `UcpProtocol` acceptance project drives `/ucp/mcp` with its own JSON-RPC client. A third-party
MCP client must be exercised by hand, on a lane whose `/.well-known/ucp` advertises the `mcp`
transport.

Steps:

- Connect an actual MCP client (e.g. Claude desktop/CLI) to `http://trunk.localhost:8088/ucp/mcp`.
- Run `tools/list` through the real client transport.
- Invoke catalog, cart, checkout, and order tools through the client. Expected: the client lists the
  UCP shopping tools and each tool call returns a valid result over the real transport.

### 2. Admin ACL / UX

The `UcpAcl` acceptance project verifies these role-specific behaviors. Use manual accounts with the
same role assignments when exploring the UI:

- `ucp.viewer` and `ucp.key_rotator`: configuration controls and Save are disabled, and the
  read-only banner is shown.
- `ucp.editor` without `sales_channel.editor`: Save on the UCP tab persists only UCP configuration.
- Users without a UCP role do not see the UCP tab or list column, and cannot read or write UCP
  configuration.
- Agentic Commerce Insights require `ucp.viewer`; product-comparison Insights keep their core
  permission behavior.

Verify that Save applies pending changes across sales channel tabs:

- Use a user with `ucp.editor` and `sales_channel.editor`.
- Change a UCP setting, an Agentic Files setting, and a Base, domain, or export setting.
- Click Save on the UCP tab.
- Use a fresh admin session or API read to confirm that all three changes persisted.
- Repeat with Save on the Base tab and confirm the same result.
- Enter an invalid sales channel setting.
- Verify that Save on the UCP tab respects the same validation as the other tabs.
- Activate UCP and verify that existing disabled files and custom content are preserved.
- Change a file setting and save again to check that no duplicate is created.

### 3. Real payment-tokenization handler

The acceptance suite expects at least one payment handler in the profile and stays red until one
exists (known blocker D2). To prove the real path, install a real tokenizing payment handler (see
[docs/payment-tokenization-handler.md](payment-tokenization-handler.md) for the implementer
checklist) and enable `payment_tokenization` for the sales channel.

Steps:

- Confirm `GET /.well-known/ucp` now advertises the installed handler under `payment_handlers` (no
  longer an empty object).
- Confirm `POST /ucp/v1/tokenize` returns a real token (not `501`):

  ```bash
  curl -i -X POST http://sw65.localhost:8088/ucp/v1/tokenize \
    -H 'Content-Type: application/json' \
    -H 'Idempotency-Key: manual-test-tokenize-1' \
    -H 'UCP-Agent: manual-tester; profile="http://sw65.localhost:8088/.well-known/ucp"' \
    -d '{"type":"tokenized","handler_id":"<installed-handler-id>","credential":{},"binding":{"type":"dev.ucp.shopping.checkout","id":"<checkout-id>"}}'
  ```

- Complete a checkout that uses the token. Expected: a paid Shopware order is created.

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
- the Agentic Commerce tab renders in the browser (exploratory)
- storefront build for the lane succeeds
- a real MCP client lists and invokes tools over `/ucp/mcp` where the endpoint is present
  (scenario 1)
- admin ACL/UX matrix is correct for `ucp.viewer`, `ucp.editor`, and `ucp.key_rotator` (scenario 2)
- with a real tokenizing handler installed, the profile advertises it, `tokenize` returns a token,
  and a paid order completes (scenario 3)
