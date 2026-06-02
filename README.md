# SwagAgenticCommerce

Shopware plugin repository for Agentic Commerce features.

This repository is intentionally scoped to commerce-facing agent integrations. It is not a generic AI playground, chat assistant, or experimentation bucket. Code added here must help external agents discover, understand, or transact with a Shopware storefront in a controlled merchant-owned way.

## Feature Areas

The plugin groups three related but separate Agentic Commerce surfaces:

| Feature | Purpose | Primary audience | Status |
| --- | --- | --- | --- |
| Universal Commerce Protocol (UCP) | Transactional protocol surface for catalog, cart, checkout, order, identity, and payment-capability flows. | Agent platforms, protocol clients, merchants configuring sales-channel exposure. | Implemented in this plugin with SDK integration and lane-aware capability exposure. |
| Native agentic discovery | Storefront discovery documents that explain how agents should interact with a shop before transactional calls happen. | Crawlers, LLM shopping agents, custom agent clients, merchants defining operating rules. | Trunk / 6.7-oriented bridge work is prepared; core reference is [shopware/shopware#17033](https://github.com/shopware/shopware/pull/17033). |
| Product feed | Outbound product feed surface for agentic/catalog consumers. | Feed consumers, marketplaces, AI catalog ingestion, merchants managing feed availability. | Planned/owned by the Agentic Commerce plugin line; details should be filled in when the feed feature lands. |

These features should share sales-channel awareness, admin UX patterns, compatibility handling, and test infrastructure where possible. They should not duplicate Store API or UCP gateway logic just because they expose different agent-facing entry points.

## Where Changes Belong

Keep protocol and Shopware responsibilities separated. This plugin directly requires the `ucp-php-sdk/symfony-bundle` Composer package, which in turn requires SDK core. The plugin should not copy SDK protocol behavior or push Shopware-specific decisions into the SDK.

| Layer | Owns | Should not own |
| --- | --- | --- |
| `ucp-php-sdk` | Protocol models, transport controllers, profile building, capability contracts, payment handler contracts, request/response envelopes, shared exception mapping, signing/idempotency/replay/profile-cache abstractions, A2A/MCP/embedded transport shaping. | Shopware repositories, Store API calls, storefront rendering details, sales-channel admin UX, Shopware version detection. |
| `SwagAgenticCommerce` plugin | Shopware adapters/gateways, sales-channel scoped config, Administration UX, lane-aware feature exposure, storefront embedded rendering, native discovery contributions, product-feed integration, demo/QA scripts. | New protocol semantics, duplicated transport controllers, generic SDK storage contracts, protocol error formats. |
| `shopware/shopware` core | Shared platform primitives that benefit more than this plugin, for example a generic Store API MCP endpoint or native agentic discovery infrastructure. | Plugin-only UCP admin behavior, PSP-specific tokenization handlers, local QA/demo shortcuts. |

Default rule: if multiple merchants/frameworks could reuse it, start in the SDK. If it depends on Shopware runtime state, sales channels, Store API, Administration, or storefront rendering, finish it in the plugin. Touch core only when the primitive is generally useful outside this plugin line.

The SDK is a required runtime dependency for this plugin line. Shopware installs it through plugin Composer commands, so `SwagAgenticCommerce::executeComposerCommands()` must stay enabled. If a future release should boot with UCP disabled when the SDK is missing, implement that as an explicit conditional service-loading mode in the plugin. Do not only suppress `getAdditionalBundles()` errors; the plugin service graph contains SDK interfaces and transport contracts.

## UCP

UCP provides the transaction contract for agentic shopping. The plugin exposes lane-aware UCP configuration in the Administration and wires Shopware catalog/cart/checkout/order behavior through the `ucp-php-sdk`.

Current responsibilities:

- Configure UCP per sales channel.
- Publish `/.well-known/ucp` with only capabilities and transports that are usable on the current Shopware line.
- Expose REST, A2A, embedded, and trunk/6.7 MCP flows through shared capability adapters.
- Keep signing keys, OAuth identity linking, payment tokenization, profile cache, and allowlists scoped to sales-channel behavior.
- Hide unsupported capabilities instead of advertising placeholders.

Developer placeholders:

- Add protocol-specific setup examples once the public SDK contracts are tagged.
- Add a compatibility table when the supported Shopware versions are finalized for release.
- Link the final UCP public documentation and conformance suite once available.

## Native Agentic Discovery

Native agentic discovery is the storefront-facing operating manual for agents. It complements UCP: discovery tells an agent how the merchant wants the shop to be used, while UCP tells the agent which transactional capabilities are technically available.

The core reference in [shopware/shopware#17033](https://github.com/shopware/shopware/pull/17033) introduces these storefront documents:

- `/agents.md`
- `/llms.txt`
- `/llms-full.txt`
- `/sitemap_agentic_discovery.xml`

Expected plugin responsibilities:

- Bridge to Shopware core discovery primitives when the current lane supports them.
- Keep discovery disabled or hidden on lanes where the core primitives do not exist.
- Surface a simple merchant/admin configuration instead of exposing raw implementation details.
- Contribute Agentic Commerce specific sections where useful, for example UCP profile location, supported shopping operations, product-feed location, rate limits, and merchant rules.

Developer placeholders:

- Document the exact admin placement once the plugin UX is finalized.
- Document which discovery sections are generated by core and which are contributed by this plugin.
- Add examples for merchant-provided rules, custom sections, cache behavior, and preview links.
- Add manual validation steps for all four discovery documents per Shopware lane.

## Product Feed

The product-feed feature is the outbound catalog surface for agentic commerce consumers. It is separate from UCP catalog operations: UCP handles transactional runtime calls, while product feeds support catalog ingestion, indexing, and marketplace-style discovery.

Reference: [shopware/SwagAgenticCommerce](https://github.com/shopware/SwagAgenticCommerce). Fill in the concrete implementation details here when the product-feed feature branch lands in this repository.

Expected plugin responsibilities:

- Expose product-feed configuration in the same Agentic Commerce admin area.
- Keep feed availability sales-channel aware.
- Reuse existing Shopware product export/feed infrastructure where possible.
- Make feed URLs discoverable from native agentic discovery documents where appropriate.
- Avoid coupling feed generation to UCP transports.

Developer placeholders:

- Fill in supported feed formats.
- Fill in feed endpoint paths and authentication/signing behavior.
- Fill in scheduling, cache, and invalidation behavior.
- Fill in interaction with native discovery documents and UCP profile metadata.
- Add QA steps for feed generation, product visibility, and multi-sales-channel domain behavior.

## Local Development

This repository keeps plugin source, QA tooling, and CI helpers only. Local Podman/Mutagen lane orchestration is intentionally not versioned here, because it is workstation setup, not plugin code.

If you use the three-lane setup (`trunk`, `6.6.x`, `6.5.x`), keep the bootstrap helpers outside the repository, for example under `~/scripts/agentic-commerce/`. The local helpers support these environment variables instead of hard-coded personal paths:

- `AGENTIC_COMMERCE_PROJECTS_ROOT`
- `AGENTIC_COMMERCE_PLUGIN_ROOT`
- `AGENTIC_COMMERCE_SDK_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_66_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_65_ROOT`
- `AGENTIC_COMMERCE_BASE_URL`

Add them to your shell profile (`~/.zshrc`, `~/.bashrc`, etc.):

```bash
export AGENTIC_COMMERCE_PLUGIN_ROOT=~/Documents/Projects/SwagAgenticCommerce
export AGENTIC_COMMERCE_SDK_ROOT=~/Documents/Projects/ucp-php-sdk
export AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT=~/Documents/Projects/shopware-trunk
export AGENTIC_COMMERCE_SHOPWARE_66_ROOT=~/Documents/Projects/shopware-6-6-branch
export AGENTIC_COMMERCE_SHOPWARE_65_ROOT=~/Documents/Projects/shopware-6-5-branch
export AGENTIC_COMMERCE_PROJECTS_ROOT=~/Documents/Projects
export AGENTIC_COMMERCE_BASE_URL=http://trunk.localhost:8088
```

Adjust the paths to match your local checkout layout.

## QA

```bash
composer ci
composer test
composer test:integration
bin/ci-smoke.sh /path/to/shopware-checkout
bin/ci-admin-smoke.sh /path/to/shopware-checkout auto
bin/ci-storefront-smoke.sh /path/to/shopware-checkout
```

Manual human test steps are documented in [docs/manual-testing.md](docs/manual-testing.md).

Lane-specific administration, build, and local-runtime differences are documented in [docs/shopware-version-differences.md](docs/shopware-version-differences.md). Short-form guidance for future coding agents is kept in [AGENTS.md](AGENTS.md).

Administration build compatibility is intentionally validated as a matrix:

- `6.5.x`: webpack only
- `6.6.x`: webpack and Vite
- `trunk` / current `6.7`: Vite only

The plugin handles this with one administration implementation and lane-aware build/test scripts, not by copying admin modules per Shopware line.

GitHub Actions checks out public `shopware/shopware` directly and private `shopware/ucp-php-sdk` with a repository secret named `PLUGINS_PAT`. Configure that token with read-only `contents` access to `shopware/ucp-php-sdk`. This matches the MCP eval workflow pattern.

The plugin stores tooling dependencies in `.tools/vendor`, not `vendor`, so lane-local Composer installs do not collide with the Shopware runtime dependency graph.

Runtime dependencies are installed through the active Shopware lane's root `composer.json`. The plugin directly requires only `ucp-php-sdk/symfony-bundle`; SDK core is resolved transitively by that bundle. Local and CI runs configure path repositories for both SDK packages only because the SDK is currently private/local. The plugin repo still keeps its own Composer file for repo-local tooling and standalone QA.

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
