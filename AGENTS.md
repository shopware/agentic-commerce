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
composer test
composer test:integration
```

The scripts delegate through `bin/run.php`, which can resolve tooling from this
plugin checkout or from a linked Shopware lane. If no local tooling or Shopware
lane exists, run the narrowest fallback checks, such as `php -l` on touched PHP
files, and explain what was not runnable.

For PHP changes, run the smallest relevant test suite first. Broaden to static
analysis, integration tests, or lane smoke checks when the touched code affects
shared runtime behavior, persistence, routes, or administration assets.

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
- Preserve review history when updating an existing PR after feedback: add a
  follow-up commit unless the user explicitly asks for an amend or force-push.
- PR descriptions should summarize what changed, why, and what validation was
  possible. Mention missing lane/tooling validation plainly.

## Further References

- [docs/shopware-version-differences.md](docs/shopware-version-differences.md)
- [docs/manual-testing.md](docs/manual-testing.md)
- [docs/full-ucp-parity-plan.md](docs/full-ucp-parity-plan.md)
