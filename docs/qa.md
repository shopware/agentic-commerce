# QA

```bash
composer ci
composer test              # unit suite (mocks, no kernel)
composer test:integration  # DB-backed integration suite (real connection, e.g. migrations)
composer test:functional   # functional suite (boots a real test kernel + Symfony browser)
bin/ci-smoke.sh /path/to/shopware-checkout
bin/ci-admin-smoke.sh /path/to/shopware-checkout auto
bin/ci-storefront-smoke.sh /path/to/shopware-checkout
```

`bin/ci-smoke.sh` resolves the SDK from Packagist at the versions `composer.json` pins. To smoke a
local SDK checkout instead, set `UCP_SDK_SOURCE=path` (and `SDK_ROOT`, which defaults to a sibling
`../ucp-php-sdk`); it is then staged into the shop and relabelled as the pinned version.

## Prefer functional tests over shell smoke

Cover behavior at the lowest layer that can express it, and reach for a PHP test before a shell
smoke check. The `functional` suite (`tests/Functional`, `composer test:functional`) boots a real
Shopware test kernel and drives UCP runtime routes end-to-end through a real Symfony browser
(against `APP_URL`, as Shopware's own functional tests do), so it is the preferred home for route,
request-context, and capability behavior — it is readable and debuggable without a deployed HTTP
stack. It covers the request-context guards and the **catalog/cart/checkout capability flows**,
including completing a checkout into a **real Shopware order** and reading it back via its context
token (via the shared `UcpFlowTestBehaviour`). It requires the booting bootstrap
(`SHOPWARE_PROJECT_DIR` unset + `APP_ENV=test`) and, like core, assumes a booted kernel — run it
against a configured lane with `composer test:functional`. In CI it gates on **every**
`shopware-matrix` lane (`CI_SMOKE_RUN_FUNCTIONAL=1`).

It runs on the **lane's own** phpunit: Shopware core's test base classes are coupled to each lane's
phpunit major (6.5→9.x, 6.6→10.x, trunk→11.x), so a single pinned phpunit can't span lanes.
`bin/run.php` prefers the platform phpunit inside a lane and `tests/bootstrap.php` registers the
plugin on the platform autoloader; ci-smoke installs Shopware's dev deps so that binary is present.
The unit/mock suites stay on the plugin's pinned `.tools` phpunit (fast, lane-independent).

Shell smoke (`bin/ci-smoke.sh`) is reserved for what the functional suite is the wrong layer for —
genuine deployed-stack / on-the-wire concerns. After the capability flows moved to the functional
suite, what stays in smoke and why:

- the **outbound signed order webhook** — actually delivered to an external endpoint with
  `signature`/`signature-input`/`content-digest` headers (on-the-wire);
- **tokenize 501** — the payment endpoint needs a _signed_ request;
- **profile/discovery** — lane-aware MCP transport detection + storefront-rendered `/llms.txt` and
  `/agents.md`;
- **admin/storefront** builds + UI shells (`bin/ci-admin-smoke.sh`, `bin/ci-storefront-smoke.sh`) —
  closer to a browser-e2e concern;
- **signed-request conformance** (`bin/validate-ucp-store.sh … conformance`).

The former `catalog` and `cart` smoke stages have been removed — that capability coverage now lives
entirely in the functional suite. The `checkout` stage resolves the seeded product itself (one
`catalog.lookup` as data setup) and stays in smoke only to drive the signed order webhook. Almost
any smoke assertion can become a functional test — when one can, move it and drop the redundant
smoke check once the functional suite gates in CI. See [AGENTS.md](../AGENTS.md) for the full
layering rationale.

Manual human test steps are documented in [docs/manual-testing.md](manual-testing.md).

Lane-specific administration, build, and local-runtime differences are documented in
[docs/shopware-version-differences.md](shopware-version-differences.md). Short-form guidance for
future coding agents is kept in [AGENTS.md](../AGENTS.md).

## Administration build matrix

Administration build compatibility is intentionally validated as a matrix:

- `6.5.x`: webpack only
- `6.6.x`: webpack and Vite
- `trunk` / current `6.7`: Vite only

The plugin handles this with one administration implementation and lane-aware build/test scripts,
not by copying admin modules per Shopware line.

GitHub Actions checks out public `shopware/shopware` and public
`agentic-commerce-alliance/ucp-php-sdk` directly. Both repositories are public, so no repository
secret or access token is required for the SDK checkout.

## Smoke execution modes

`bin/ci-smoke.sh` supports two execution modes:

- Local default: `warm` Reuses an already prepared Shopware web volume and only refreshes
  `custom/plugins/SwagAgenticCommerce` and `custom/ucp-php-sdk` before running the smoke flow.
- CI and full validation: `cold` Rebuilds the Shopware web volume from the checkout before running
  the smoke flow.

Examples:

```bash
# Fast local rerun against an already bootstrapped lane.
bin/ci-smoke.sh /path/to/shopware-checkout

# Force a full rebuild locally.
CI_SMOKE_MODE=cold bin/ci-smoke.sh /path/to/shopware-checkout
```

You can also override stack cleanup explicitly with `CI_SMOKE_KEEP_STACK=0|1`. By default, warm mode
keeps the stack running and cold mode tears it down at the end.

Administration and storefront validation should always prove both halves:

- build succeeds
- the rendered UI shell actually loads afterward

`bin/ci-admin-smoke.sh` checks the administration login shell after the build.
`bin/ci-storefront-smoke.sh` builds the storefront, compiles the theme, and checks the live homepage
and cart shell. For local frontend work, still follow up with a real browser pass on the active
lane.
