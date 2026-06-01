# SwagAgenticCommerce

Standalone Shopware plugin repository for the cross-version UCP and agentic commerce integration.

## Local development

This repository keeps plugin source, QA tooling, and CI helpers only. Local Podman/Mutagen lane orchestration is intentionally not versioned here, because it is workstation setup, not plugin code.

If you use the three-lane setup (`trunk`, `6.6.x`, `6.5.x`), keep the bootstrap helpers outside the repository, for example under `~/scripts/agentic-commerce/`. The local helpers created for this workstation support these environment variables instead of hard-coded personal paths:

- `AGENTIC_COMMERCE_PROJECTS_ROOT`
- `AGENTIC_COMMERCE_PLUGIN_ROOT`
- `AGENTIC_COMMERCE_SDK_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_66_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_65_ROOT`
- `AGENTIC_COMMERCE_BASE_URL`

## QA

```bash
composer ci
composer test
composer test:integration
bin/ci-smoke.sh /path/to/shopware-checkout
bin/ci-admin-smoke.sh /path/to/shopware-checkout auto
bin/ci-storefront-smoke.sh /path/to/shopware-checkout
```

Manual human test steps are documented in [docs/manual-testing.md](/Users/b.meyer/Documents/Projects/SwagAgenticCommerce/docs/manual-testing.md).

Lane-specific administration, build, and local-runtime differences are
documented in [docs/shopware-version-differences.md](/Users/b.meyer/Documents/Projects/SwagAgenticCommerce/docs/shopware-version-differences.md).
Short-form guidance for future coding agents is kept in
[AGENTS.md](/Users/b.meyer/Documents/Projects/SwagAgenticCommerce/AGENTS.md).

Administration build compatibility is intentionally validated as a matrix:

- `6.5.x`: webpack only
- `6.6.x`: webpack and Vite
- `trunk` / current `6.7`: Vite only

The plugin handles this with one administration implementation and
lane-aware build/test scripts, not by copying admin modules per Shopware line.

GitHub Actions checks out public `shopware/shopware` directly and private
`shopware/ucp-php-sdk` with a repository secret named `PLUGINS_PAT`. Configure
that token with read-only `contents` access to `shopware/ucp-php-sdk`. This
matches the MCP eval workflow pattern.

The plugin stores tooling dependencies in `.tools/vendor`, not `vendor`, so lane-local Composer installs do not collide with the Shopware runtime dependency graph.

Runtime dependencies are installed through the active Shopware lane's root `composer.json`. The plugin repo still keeps its own Composer file for repo-local tooling and standalone QA.

`bin/ci-smoke.sh` supports two execution modes:

- Local default: `warm`
  Reuses an already prepared Shopware web volume and only refreshes `custom/plugins/SwagAgenticCommerce` and `custom/ucp-php-sdk` before running the smoke flow.
- CI and full validation: `cold`
  Rebuilds the Shopware web volume from the checkout before running the smoke flow.

Examples:

```bash
# Fast local rerun against an already bootstrapped lane.
bin/ci-smoke.sh /path/to/shopware-checkout

# Force a full rebuild locally.
CI_SMOKE_MODE=cold bin/ci-smoke.sh /path/to/shopware-checkout
```

You can also override stack cleanup explicitly with `CI_SMOKE_KEEP_STACK=0|1`. By default, warm mode keeps the stack running and cold mode tears it down at the end.

Administration and storefront validation should always prove both halves:

- build succeeds
- the rendered UI shell actually loads afterward

`bin/ci-admin-smoke.sh` checks the administration login shell after the build. `bin/ci-storefront-smoke.sh` builds the storefront, compiles the theme, and checks the live homepage and cart shell. For local frontend work, still follow up with a real browser pass on the active lane.
