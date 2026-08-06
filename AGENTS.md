# Agent Notes

This repository is a Shopware plugin, not Shopware core. Keep changes compatible
with Shopware `6.5.x`, `6.6.x`, and the latest/trunk line from one codebase.
Do not duplicate administration modules, controllers, transport logic, or UCP
runtime code per Shopware version. Use shared code plus explicit feature
detection.

## Shopware Plugin Context

- Shopware uses a custom Data Abstraction Layer. Do not add Doctrine ORM,
  Doctrine annotations, or ORM-style repositories.
- Use DAL `Criteria` and `EntityRepository` for Shopware entity access.
- Prefer Shopware extension mechanisms that already exist: events,
  subscribers, routes, DAL entities, Twig blocks/templates, and service
  decoration only when event timing is not enough.
- Keep public plugin contracts explicit. REST/Admin/Store API routes, DAL
  entities, template context, and documented SDK/UCP behavior are the BC
  surface. Controllers, subscribers, loaders, renderers, and discovery services
  should be internal unless they are intended extension points.
- Follow `docs/public-api-boundaries.md` for PHP API scope. Classes,
  interfaces, and traits in internal-by-default namespaces must carry
  `@internal`; keep package annotations out of scope unless a task explicitly
  asks for them.
- Keep services unit-testable without external systems. Translate framework
  objects (`Request`, IO, database, filesystem, HTTP) at the edge before calling
  application services.
- Prefer public readonly properties for simple transparent value objects. Do
  not add DTOs only to model a private handoff inside one class.

## Validation

Use the repository scripts when dependencies are available:

```bash
composer cs
composer phpstan
composer rector
composer test              # unit suite (mocks, no kernel)
composer test:integration  # DB-backed integration suite (real connection, e.g. migrations)
composer test:functional   # functional suite, boots a real test kernel + Symfony browser
```

The scripts delegate through `bin/run.php`, which can resolve tooling from this
plugin checkout or from a linked Shopware lane. If no local tooling or Shopware
lane exists, run the narrowest fallback checks, such as `php -l` on touched PHP
files, and explain what was not runnable.

For PHP changes, run the smallest relevant test suite first. Broaden to static
analysis, integration tests, or lane smoke checks when the touched code affects
shared runtime behavior, persistence, routes, or administration assets.

### Test layering: prefer functional over smoke

Cover behavior at the lowest layer that can express it, and prefer a PHP test
over a shell smoke check whenever the behavior fits one — PHP tests are readable,
debuggable, and run without a deployed HTTP stack:

1. **`unit`** (`tests/Unit`, `composer test`) — pure logic with mocks; no kernel.
   Mock-only collaboration tests (no kernel boot) live here too — a test that only
   wires mocks is a unit test regardless of how many collaborators it stubs.
2. **`integration`** (`tests/Integration`, `composer test:integration`) — DB-backed
   tests that use a real Doctrine connection through the kernel (e.g. the migration
   tests). Reserve this tier for tests that genuinely touch the database/kernel;
   mock-only tests belong in `unit`.
3. **`functional`** (`tests/Functional`, `composer test:functional`) — boots a
   real Shopware test kernel and drives UCP runtime routes end-to-end through a
   real Symfony `KernelBrowser` (the full HttpKernel request/response cycle,
   kernel events included), against `APP_URL` — the test database's default
   storefront sales-channel domain — exactly as Shopware's own functional tests
   do. This is the **preferred** home for route/request-context/capability
   behavior that used to be asserted by shell smoke. It already covers the
   request-context guards (missing UCP-Agent → 422, OAuth metadata → 501) and the
   **catalog/cart/checkout capability flows** — including completing a checkout
   into a **real Shopware order** and reading it back via its persisted context
   token. Requires the booting bootstrap (`SHOPWARE_PROJECT_DIR` unset +
   `APP_ENV=test`). Like core, the suite assumes a booted kernel (no per-test
   skip-guards) — run it via `composer test:functional` against a configured lane
   (e.g. the `shopware-6-6-branch-web` container), never under the fast-path
   bootstrap. It gates in CI on **every** `shopware-matrix` lane
   (`CI_SMOKE_RUN_FUNCTIONAL=1`).

   The flow tests share `UcpFlowTestBehaviour`, which reproduces the SDK
   request-context handshake offline: it sets the sales-channel config the smoke
   sets (`active`, `signaturePolicy=log`, `continueUrlTemplate`), seeds a product
   with the core `ProductBuilder` fixture, and hands the merchant's own
   capability-bearing `PlatformProfile` (built via `ProfileBuilderInterface` with
   `enabledCapabilities`) to a test `AgentProfileFetcherInterface`. The stub (not
   the SDK profile cache) is required: the real fetcher runs an SSRF URL-safety
   check that rejects the lane's `*.localhost` host. The override is wired the way
   core overrides services for tests — a `test`-environment-only service swap
   (`TestAgentProfileFetcherCompilerPass` replaces the SDK's `HttpAgentProfileFetcher`
   with `Ucp\Test\StaticAgentProfileFetcher`), so the test just calls `setProfile()`
   on it; no kernel reboot is needed.

   **Runs on the lane's own phpunit, not the plugin's.** The suite uses Shopware
   core's test base classes (`IntegrationTestBehaviour`), which are coupled to the
   lane's phpunit major — 6.5 pins 9.x (the removed `getName()`), 6.6 10.x, trunk
   11.x — so a single pinned phpunit cannot span all lanes. `bin/run.php` prefers
   the platform phpunit binary when inside a lane, and `tests/bootstrap.php`
   registers the plugin's `src` + `Tests` namespaces on the platform autoloader,
   so the suite runs on whatever phpunit the lane ships. ci-smoke installs
   Shopware's dev deps (the smoke stack is `--no-dev`) to make that binary
   available. This is distinct from the **unit/mock** suites, which deliberately
   run on the plugin's pinned `.tools` phpunit (fast, lane-independent) — do not
   remove that pin (the PHP-8.1 lane runs against 6.5/phpunit-9, where our
   attribute-based tests would otherwise break).

**Shell smoke is the last resort, not the default.** Almost any smoke assertion can
be expressed as a Symfony-browser functional test, so default to that: add a check
to `bin/lib/smoke/*` only when it genuinely needs the deployed stack (real on-the-wire
delivery, a live external endpoint, a per-lane JS build). Anything provable through a
booted kernel belongs in the `functional` suite. When you migrate a smoke assertion
into a functional test, remove the now-redundant smoke check once the functional suite
gates in CI, so coverage moves rather than duplicates.

#### What still lives in shell smoke today, and why

These are the checks not yet migrated because they exercise genuine deployed-stack /
on-the-wire behavior that a booted kernel does not observe directly. They are not
"impossible as functional tests" in principle — an e2e/browser harness could cover
most of them — but the booted-kernel suite is the wrong layer for them today:

- **Outbound signed order webhook** (`smoke/checkout.sh`) — asserts the webhook is
  actually *delivered* to an external capture endpoint with `signature`,
  `signature-input`, and `content-digest` headers. A booted-kernel test can at most
  assert the webhook was *dispatched*; the signed HTTP on the wire is e2e.
- **Tokenize 501** (`smoke/identity.sh`) — the payment endpoint requires a
  *signed* request; the smoke only reaches it because it fetches a real profile
  with signing keys over HTTP.
- **Profile / discovery** (`smoke/discovery.sh`) — lane-aware MCP transport
  detection (depends on the live Store-API MCP endpoint) and the
  storefront-rendered `/llms.txt` + `/agents.md` fallbacks (real `Content-Type`
  and rendering).
- **Admin & storefront** (`bin/ci-admin-smoke.sh`, `bin/ci-storefront-smoke.sh`) —
  per-lane JS builds (webpack/Vite) and the rendered admin/storefront shells.
  (These are closer to a Playwright/browser-e2e concern than a bash one; treat the
  bash check as a pragmatic build-plus-shell gate, not the ideal long-term home.)
- **Signed-request conformance** (`bin/validate-ucp-store.sh … conformance`).

**No capability duplication:** the catalog and cart smoke stages have been removed — their
capability coverage lives entirely in the `functional` suite now. The `checkout` stage stays
in smoke solely to drive the signed order webhook; it resolves the seeded product's title and
price itself (a single `catalog.lookup` as data setup, not a catalog assertion), so it no
longer depends on a `catalog → cart → checkout` stage chain.

### Shell smoke and lint tooling

The `bin/` smoke scripts share helpers from `bin/lib/`:

- `bin/lib/ucp-http.sh` — curl wrappers (`curl_required`, `ucp_status`,
  `ucp_expect_status`, `ucp_jsonrpc`), assertions, and `next_idempotency_key`.
  `ucp_http_init` builds the `UCP-Agent` header and the wrappers auto-inject it,
  so a runtime request can never silently omit it (the SDK rejects a missing
  header with `422`).
- `bin/lib/lane.sh` — container helpers (`web`, `db_query`, …) operating on the
  sourcing script's `compose` array and `container_runtime`.
- `bin/lib/smoke/*.sh` — `bin/ci-smoke.sh` is a thin orchestrator that, after
  bootstrap, sources and runs named stage modules (`discovery`, `identity`,
  `checkout`). Each prints a `>>> smoke: <stage>` banner, so a
  failure names the area. Stages share the orchestrator's shell scope (they are
  sourced, not subprocesses); add a new check by adding a `smoke_<stage>` module
  and calling it from the orchestrator. Before adding a smoke check, confirm it
  cannot be a `functional` test (see *Test layering* above) — smoke is
  for deployed-stack concerns only. With `CI_SMOKE_RUN_FUNCTIONAL=1` (set on
  every `shopware-matrix` lane) the orchestrator installs Shopware's dev deps and
  runs the functional suite on the lane's own phpunit after the HTTP smoke.

Lint every shell script with `shellcheck -x bin/*.sh bin/lib/*.sh` (the CI
`shell-lint` job; `.shellcheckrc` disables `SC2016` for jq filters). `-x` follows
the `# shellcheck source=` directives so the sourced modules are validated in
context.

Signed / strict-signature request verification is **not** covered by the smoke
(it sends unsigned requests under log policy); use the conformance suite,
`bin/validate-ucp-store.sh <url> '' conformance`.

### Composer advisory reporting

Compatibility lanes may need to resolve historical Shopware dependencies with
known advisories. Keep Composer's security blocking disabled for these disposable
CI containers, but preserve visibility through the centralized reporting flow:

- The `php-quality` PHP 8.2 lane captures the plugin lock's direct dependency
  report. The three `shopware-matrix` lanes (`6.5.x`, `6.6.x`, and `trunk`)
  capture the dependencies resolved in each installed Shopware environment.
- Those four sources upload normalized JSON as uniquely named
  `composer-audit-*` artifacts with short retention. Composer versions that emit
  no JSON for a clean audit must still produce an empty report.
- The non-blocking `composer-security-report` job downloads those artifacts,
  deduplicates advisory IDs, and writes exactly one workflow warning and one job
  summary for the run.
- Do not add Composer advisory annotations or summaries to `php-quality`,
  `admin-matrix`, `storefront-matrix`, MySQL, or individual smoke jobs. A
  nonzero `composer audit` status can also represent abandoned packages; inspect
  the JSON `advisories` data instead of treating the exit code as proof of a
  security advisory.
- Keep `composer-security-report` outside `validation-gate`. Missing, malformed,
  or known-vulnerable compatibility reports must remain visible without blocking
  functional validation.

Do not reuse a complete installed Shopware tree across these jobs. Lanes use
different Shopware versions and administration build modes, and the tests mutate
dependencies, assets, databases, and caches. Composer's download cache can be
optimized separately without coupling otherwise isolated compatibility jobs.

## Administration Build Matrix

The administration build system differs by lane:

| Lane | Required admin build validation |
| --- | --- |
| `6.5.x` | webpack only |
| `6.6.x` | webpack and Vite |
| `trunk` / current `6.7+` | Vite only |

Use `bin/ci-admin-smoke.sh <shopware-dir> <mode>` for build validation:

```bash
bin/ci-admin-smoke.sh /path/to/shopware-6-5-branch webpack
bin/ci-admin-smoke.sh /path/to/shopware-6-6-branch webpack
bin/ci-admin-smoke.sh /path/to/shopware-6-6-branch vite
bin/ci-admin-smoke.sh /path/to/shopware-trunk vite
```

The script handles the important differences:

- `6.5.x` rejects Vite and relaxes local Node engine checks for webpack
  validation.
- `6.6.x` can run both paths; the script toggles `ADMIN_VITE` in
  `var/config_js_features.json` and restores it afterward.
- `trunk` rejects webpack and uses Vite.
- Every build must be followed by an admin shell/browser check. A successful
  JavaScript build alone is not enough.

## Administration Compatibility Rules

- Keep UCP under the `shop` settings group on `6.5.x`/`6.6.x` unless the
  `commerce` group exists. `trunk` uses `commerce`.
- Legacy `sw-card` and newer Meteor `mt-card` wrappers differ. Layout fixes must
  work for both.
- `6.5.x` can still consume legacy static administration assets. If the browser
  shows stale labels or layout, check installed assets under
  `public/bundles/swagagenticcommerce/` before changing source code.
- Do not copy or sync `var/plugins.json` manually. It is generated per Shopware
  lane by `bundle:dump`; if UCP is missing from the admin shell, rerun the lane
  admin smoke instead of reusing metadata from another lane.
- Treat `src/Resources/public/` and
  `public/bundles/swagagenticcommerce/` as generated admin output. The lane sync
  helper ignores these paths and the admin smoke script cleans them before each
  build to avoid webpack/Vite cross-lane pollution.
- Browser validation is mandatory on each lane after admin UI changes.

## Runtime Compatibility Rules

- Sales-channel UCP config lives in the plugin table
  `swag_agentic_commerce_ucp_config`. `SystemConfig` is only a legacy fallback
  and read-through backfill path; do not add new UCP settings there.
- Keep REST, A2A, embedded, and MCP on the shared SDK operation/capability
  layer. Shopware-specific MCP code is limited to the `/ucp/mcp` proxy and Store
  API MCP tool registrations.
- Customer-facing runtime flows must use Store API route boundaries wherever
  they exist. This is a hard rule for UCP adapters/gateways and especially
  catalog, cart, checkout, customer, identity, and order flows. Inject the
  relevant Store API route abstraction instead of using DAL repositories,
  manually creating customers, or mutating sales-channel context state by hand.
  Direct repositories are only acceptable for plugin-owned configuration,
  admin/internal metadata, compatibility discovery, or a documented exception
  where no Store API route exists.
- MCP write tools must expose object payload schemas (`payload` plus `id` where
  needed), not JSON-string payload arguments.
- Embedded pages require configured `embeddedAllowedOrigins`; the plugin returns
  controlled `403` responses for missing or non-allowlisted `Origin` headers and
  sets CSP frame ancestors from `embeddedFrameAncestors`.
- Feature-detect Shopware capabilities instead of comparing versions unless a
  version check is the only stable signal.
- Keep migrations safe across all supported lanes. Do not assume newer core
  tables, constants, entity definitions, services, or administration APIs exist.

## Boyscouting Scope

- When asked to make a specific cleanup or behavioral change, look for safe
  opportunities to apply the same improvement across the touched file.
- If the same pattern appears in nearby files or a broader low-risk scope,
  mention that proactively and suggest extending the cleanup.
- When adding or touching unit tests, look for low-hanging missing coverage
  paths in the same domain or command surface that can be covered cheaply and
  locally.
- Keep the scope aligned with the request: avoid unrelated refactors, but do not
  miss obvious consistency fixes that make the codebase simpler.

## Test Structure

- Write test methods as clear executable examples. Keep scenario-specific data,
  action, and assertions visible in the test body.
- Move stable boilerplate such as mock services, the class under test, command
  testers, and repeated collaborators into `setUp()`/`tearDown()` when that
  lets tests focus on the scenario.
- Prefer PHPUnit mocks/stubs for interfaces. Avoid throwaway anonymous classes
  inside test methods unless the concrete behavior is the subject of the test.
- Avoid reflection-uninitialized final services in tests. Construct real
  collaborators with mocks or extract a narrower interface where that is already
  part of the production design.
- Keep helpers smaller than the code they replace. Helpers may create entities,
  files, or value objects, but should not hide meaningful scenario wiring or
  assertions.
- Prefer one focused test method per distinct exception or behavior over broad
  data providers when each case has its own meaning.
- Use named `yield` cases in data providers. Case names should describe the
  behavior being proven, not restate raw input values.
- Do not add `#[CoversClass]`, `#[CoversFunction]`, or `#[CoversNothing]` to
  integration tests.
- Do not mock DBAL persistence behavior for adapter confidence. SQL/database
  adapters should have integration coverage when persistence behavior matters.

## Bug Fix Root Cause And Scope

- Treat fix suggestions from issues as hypotheses, not instructions to follow
  blindly. Reason from first principles about the actual failure mode before
  choosing an implementation.
- Prefer the least invasive fix that correctly addresses the root cause.
- Fix issues at the boundary where the root cause actually lives instead of
  spreading compensating changes across unrelated components.
- Match the fix location to the bug scope. A plugin-specific bug belongs in the
  plugin, while a Shopware core bug should be fixed upstream instead of worked
  around repeatedly here.
- Conversely, keep feature-specific bugs out of broad shared infrastructure when
  a general change could negatively affect other plugin behavior.
- Always do a root cause analysis to identify where the real issue lives.

## Pull Requests

- Keep PRs focused. Test-only refactors, compatibility fixes, runtime behavior,
  and administration UI work should be separate unless the user asks otherwise.
- Use conventional commit style for commit messages and keep PR titles/messages
  short.
- Preserve review history when updating an existing PR after feedback: add a
  follow-up commit unless the user explicitly asks for an amend or force-push.
- PR descriptions should summarize what changed and why. Do not add validation
  sections; CI owns validation reporting.
- Need an install-ready package for a reviewer? Add the `build:zip` label to the
  PR. `.github/workflows/package-zip.yml` then builds, validates, and uploads a
  `SwagAgenticCommerce.zip` run artifact, and rebuilds it on every push while the
  label stays on. It is opt-in on purpose, so do not wire it into the default CI
  matrix or the `validation-gate`. See the README `Release` section for details.

## Releases

Store releases run from `main` HEAD via `.github/workflows/store-release.yml` after
that commit has a green `validation-gate`. Bump `composer.json` `version`, the admin
`package.json` + lock, and both changelogs (`# <version>`) in the release PR. See the
README `Release` section for the full flow. Two recurring pitfalls have their own
subsections there — read them before the change, not after CI is green:

- **SDK version floor.** `ucp-php-sdk/symfony-bundle` is required as an explicit range,
  currently `>=0.0.5 <0.1.0`, **not** a caret — a caret on `0.0.x` is locked to that exact
  patch (the plugin's original `^0.0.2` never resolved `0.0.3`) and excluded every future
  release. Read the lower bound out of `composer.json` rather than from here. The range lets
  new `0.0.x` releases reach merchants without a plugin change, so SDK breakage can
  arrive on its own; that is why CI must keep testing against the moving SDK `main`.
  It still only *permits* a newer tag — an install with an existing lock resolves the
  older one — so never merge release-bound code that references SDK symbols living
  only on the SDK `main` branch or an unmerged SDK PR: CI passes against `main` while
  such an install fatals with `Class "…" not found`. Depending on a new symbol means
  raising the range's **lower bound** in `composer.json` and keeping the two forced
  `versions` in `ci.yml`'s *Configure private SDK path repositories* step and the two
  in `bin/ci-smoke.sh` at or above it, while leaving `UCP_SDK_REF` on `main`.
- **Migrations.** The runner never re-runs an applied migration. Never edit the
  effect of a migration already shipped in a tagged release (upgraded shops keep the
  old schema); add a new idempotent forward migration instead. Editing a migration
  that exists only in the current unreleased cycle is fine — verify with
  `git show <tag>:<migration-path>` that no release tag contains it.

## Further References

- [docs/shopware-version-differences.md](docs/shopware-version-differences.md)
- [docs/manual-testing.md](docs/manual-testing.md)
- [docs/full-ucp-parity-plan.md](docs/full-ucp-parity-plan.md)
- [docs/public-api-boundaries.md](docs/public-api-boundaries.md)
