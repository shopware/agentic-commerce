# SwagAgenticCommerce

Shopware plugin repository for Agentic Commerce features.

This repository is intentionally scoped to commerce-facing agent integrations. It is not a generic AI playground, chat assistant, or experimentation bucket. Code added here must help external agents discover, understand, or transact with a Shopware storefront in a controlled merchant-owned way.

## Feature Areas

The plugin groups three related but separate Agentic Commerce surfaces:

| Feature | Purpose | Primary audience | Status |
| --- | --- | --- | --- |
| Universal Commerce Protocol (UCP) | Transactional protocol surface for catalog, cart, checkout, order, identity, and payment-capability flows. | Agent platforms, protocol clients, merchants configuring sales-channel exposure. | Implemented in this plugin with SDK integration and lane-aware capability exposure. |
| Native agentic discovery | Storefront discovery documents that explain how agents should interact with a shop before transactional calls happen. | Crawlers, LLM shopping agents, custom agent clients, merchants defining operating rules. | Implemented through the core bridge on lanes with sales-channel file support and through plugin fallback routes on older lanes. |
| Product feed | Outbound product feed surface for agentic/catalog consumers. | Feed consumers, marketplaces, AI catalog ingestion, merchants managing feed availability. | Implemented with Shopware product exports for OpenAI JSONL and Google Shopping XML feeds. |

These features should share sales-channel awareness, admin UX patterns, compatibility handling, and test infrastructure where possible. They should not duplicate Store API or UCP gateway logic just because they expose different agent-facing entry points.

## Where Changes Belong

Keep protocol and Shopware responsibilities separated. This plugin directly requires the `ucp-php-sdk/symfony-bundle` Composer package, which in turn requires SDK core. The plugin should not copy SDK protocol behavior or push Shopware-specific decisions into the SDK.

| Layer | Owns | Should not own |
| --- | --- | --- |
| `ucp-php-sdk` | Protocol models, transport controllers, profile building, capability contracts, payment handler contracts, request/response envelopes, shared exception mapping, signing/idempotency/replay/profile-cache abstractions, A2A/MCP/embedded transport shaping. | Shopware repositories, Store API calls, storefront rendering details, sales-channel admin UX, Shopware version detection. |
| `SwagAgenticCommerce` plugin | Shopware adapters/gateways, sales-channel scoped config, Administration UX, lane-aware feature exposure, storefront embedded rendering, native discovery contributions, product-feed integration, demo/QA scripts. | New protocol semantics, duplicated transport controllers, generic SDK storage contracts, protocol error formats. |
| `shopware/shopware` core | Shared platform primitives that benefit more than this plugin, for example a generic Store API MCP endpoint or native agentic discovery infrastructure. | Plugin-only UCP admin behavior, PSP-specific tokenization handlers, local QA/demo shortcuts. |

Default rule: if multiple merchants/frameworks could reuse it, start in the SDK. If it depends on Shopware runtime state, sales channels, Store API, Administration, or storefront rendering, finish it in the plugin. Touch core only when the primitive is generally useful outside this plugin line.

Customer-facing runtime flows must enter Shopware through Store API boundaries wherever such a boundary exists. This is a hard architecture rule for UCP adapters, gateways, and shopping flows such as catalog, cart, checkout, customer, identity, and order reads. Prefer injecting the relevant Store API route abstraction, for example `Abstract*Route`, so Shopware decorators, sales-channel visibility, validation, customer ownership checks, context-token handling, and route events stay in effect. Do not implement buyer-facing behavior with direct DAL repository reads/writes, manual customer creation, or hand-rolled context mutation. Repository access is acceptable for plugin-owned configuration, admin/runtime metadata, compatibility discovery, or a documented exception where no Store API route exists.

The SDK is a required runtime dependency for this plugin line. Shopware installs the public Packagist packages through plugin Composer commands, so `SwagAgenticCommerce::executeComposerCommands()` stays enabled. If a future release should boot with UCP disabled when the SDK is missing, implement that as an explicit conditional service-loading mode in the plugin. Do not only suppress `getAdditionalBundles()` errors; the plugin service graph contains SDK interfaces and transport contracts.

## UCP

UCP provides the transaction contract for agentic shopping. The plugin exposes lane-aware UCP configuration in the Administration and wires Shopware catalog/cart/checkout/order behavior through the `ucp-php-sdk`.

### Set up UCP on a sales channel

The whole path from "plugin activated" to "first UCP request answered", for one sales channel. You need a Storefront or Headless sales channel with at least one domain; every other channel type is refused.

1. **Configure the channel in one step.**

   ```bash
   # a laptop or a test system
   bin/console ucp:setup --sales-channel=Storefront --dev

   # production: strict signatures, and only the named platform may talk to the channel
   bin/console ucp:setup --sales-channel=Storefront --agent-host=agent.example.com
   ```

   `ucp:setup` switches UCP on for the channel, writes the security defaults, generates a signing key if the channel has none, runs the readiness checks and prints the profile URL. `--dev` accepts unsigned requests (policy `log`) and puts the channel's own domain hosts and `localhost` on the allowlists, so the shop can act as its own agent. Without `--dev` the policy is `strict` and nothing is allowed until you name a platform host. `--dry-run` shows the resulting config without writing it.

2. **Locally only: turn on the SDK's development mode.** Every UCP request names the *calling agent's* profile URL, and the SDK's URL-safety rules refuse everything a laptop can offer (`*.localhost` names and container hostnames resolve to loopback). In development mode the shop accepts its own `/.well-known/ucp` as that profile instead, so no second server is needed.

   ```bash
   export SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE=1
   bin/console cache:clear
   ```

   Never set this in production: it also admits plain-http and loopback profile hosts.

3. **Make the first request.** The SDK bundle prints it for you, headers and a minimal body included:

   ```bash
   bin/console ucp:dev:request catalog.search --base-uri=http://shop.localhost:8088
   ```

   Paste the printed `curl`. Expect `200` with `"status": "success"` in the `ucp` envelope. Without an argument the command lists every operation; `--id` fills in product and resource ids.

4. **Change and re-check.** Exposure (active, profile domain, capabilities, transports) lives in the Administration under the sales channel; allowlists and policy in `ucp:config:set`; `bin/console ucp:config:validate --sales-channel=Storefront` re-runs the readiness checks any time.

What the shortcut in step 2 does and does not prove, and what to do when you need a real second profile (strict signatures, the conformance agent, a staging platform), is in the SDK's `docs/local-testing.md`. Which UCP version the plugin serves and what an SDK bump means for a shop is in [docs/ucp-version-support.md](docs/ucp-version-support.md).

Current responsibilities:

- Configure UCP per sales channel — Storefront and Headless only. Every other type, product feed channels included, is refused: the card is hidden, the Admin API and `ucp:config:set` return an error, and a stale `active` flag reads as off.
- Store sales-channel UCP config in the plugin-owned `swag_agentic_commerce_ucp_config` table. Legacy `SystemConfig` values are read only as a compatibility fallback and backfilled into the table when found.
- Publish `/.well-known/ucp` with only capabilities and transports that are usable on the current Shopware line.
- Expose REST, A2A (`/.well-known/agent-card.json`, `/ucp/a2a`), embedded (`/ucp/embedded/*`), and trunk/6.7 MCP (`/ucp/mcp`) flows through shared capability adapters.
- Implement customer-facing adapter/gateway behavior through Store API routes, not direct repositories, so UCP follows the same sales-channel and customer-context rules as storefront clients.
- Keep signing keys, OAuth identity linking, payment tokenization, platform-profile cache, and allowlists scoped to sales-channel behavior.
- Require explicit embedded origin and frame-ancestor configuration before embedded pages render cross-origin; disallowed or missing origins return controlled UCP errors.
- Hide unsupported capabilities instead of advertising placeholders.

### Console commands

UCP is administered from the CLI for everything except the per-channel Exposure settings (active, profile domain, capabilities, transports), which live in the Administration. Every command takes `--sales-channel` (id **or** name; omit it to pick interactively). Run `bin/console ucp:channels` first to see channel ids and which channels currently expose UCP.

| Command | Purpose |
| --- | --- |
| `ucp:setup --sales-channel=… [--dev] [--agent-host=…] [--dry-run]` | Configure a channel for UCP in one step: exposure, security defaults, signing key, readiness check, first request. See *Set up UCP on a sales channel* above. |
| `ucp:channels` | List the UCP-capable sales channels, their ids and UCP exposure (`exposed` / `off`). |
| `ucp:config:show --sales-channel=…` | Print the resolved UCP config for a channel. |
| `ucp:config:set --sales-channel=… …` | Set the non-UI config fields (below). Only the options you pass change; the rest is preserved by a merge, so admin-managed Exposure fields are never reset. |
| `ucp:dev:request [operation] [--id=…] --base-uri=…` | SDK command. Print a ready-to-run `curl` for a UCP operation with the shop's own profile as the agent (needs the development mode from step 2 above). |
| `ucp:signing-keys:{generate,list,show-public,retire,delete} --sales-channel=…` | Manage a channel's signing keys — thin subclasses of the SDK commands that map `--sales-channel` to the SDK tenant. |

`ucp:config:set` fields (run it with `--help` for per-option examples):

- `--signature-policy=strict|log|off`
- `--idempotency=true|false`
- `--agent-allowlist`, `--remote-profile-allowlist`, `--platform-allowlist` — bare hosts, no scheme (repeatable)
- `--embedded-allowed-origins`, `--embedded-frame-ancestors` — origins, scheme + host (repeatable)
- `--webhook-url-override` — absolute https URL whose host is in an allowlist (pass an empty value to clear)
- `--continue-url-template` — absolute URL supporting `{checkoutId}`, `{cartId}`, `{salesChannelId}` (pass an empty value to clear)

Options are omit-to-leave-unchanged; passing none is an error rather than a silent no-op. Example — allow an embedded checkout to be framed by ChatGPT and require idempotency:

```bash
bin/console ucp:config:set --sales-channel=Storefront \
    --embedded-allowed-origins=https://chatgpt.com \
    --embedded-frame-ancestors=https://chatgpt.com \
    --idempotency=true
```

The SDK bundle also ships storage-maintenance commands (not sales-channel scoped): `ucp:storage:cleanup` purges all expired SDK records (OAuth state, idempotency, negotiation sessions, profile cache, replay nonces, retired keys) using the configured retention windows, while `ucp:storage:cleanup-signature-nonces` purges only replay nonces with a tunable `--older-than-seconds`. Schedule the former periodically; reach for the latter only to prune nonces on a tighter cadence.

### Compatibility and transport security

The plugin supports Shopware `6.5.8` and newer, `6.6.x`, and trunk/current `6.7+` from one codebase. `6.5.0.0` through `6.5.7.4` are not supported: they ship Symfony 6.3, while both the plugin's own controllers and the UCP SDK need the routing attribute and the `symfony/config` version that arrived in Symfony 6.4. A shop on one of those takes the in-line update to `6.5.8.x`, which stays inside the same minor. Capability exposure is feature-detected at runtime: unsupported transports are removed from the UCP profile instead of returning dead links. MCP is only advertised when the current lane has the required Store API MCP infrastructure; REST, A2A, and embedded routes stay on the shared SDK capability layer.

Every UCP sales channel has its own tenant configuration. The default setup is intentionally closed: profile exposure must be enabled for the channel, remote platform/profile hosts must be allowlisted where configured, and embedded pages require both `embeddedAllowedOrigins` and `embeddedFrameAncestors`. Until both are configured, every embedded request returns a controlled `403` UCP response.

Once configured, the embedded surface is protected by three distinct mechanisms — do not mistake any one of them for the others:

- **Framing** is restricted by `embeddedFrameAncestors`, emitted as `Content-Security-Policy: frame-ancestors ...` (and `X-Frame-Options` is removed so the CSP is authoritative). This is what stops a non-allowlisted page from embedding the surface, and it is enforced by the browser.
- **Cross-origin reads** are restricted by `embeddedAllowedOrigins`, emitted as `Access-Control-Allow-Origin`. A request carrying an `Origin` header that is not on the allowlist is rejected with `403`. Note that browsers omit `Origin` on iframe and top-level `GET` navigations, so an *absent* `Origin` is deliberately not treated as a denial signal — rejecting it would break every real browser load of the embedded page. `embeddedAllowedOrigins` is a CORS control, not a server-side authorization gate, and cannot constrain a non-browser client.
- **Authorization for the payload** is possession of the cart or checkout token in the URL. That path segment is the Shopware sales-channel context token; anyone holding it can already read and write that cart through the REST and A2A transports. **Treat the embedded URL as a secret.** Embedded responses are sent with `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, and `X-Robots-Tag: noindex, nofollow` so the token does not leak into shared caches, search indexes, or `Referer` headers, and they vary by `Origin`.

Signed-request handling, idempotency, replay nonce storage, profile cache storage, OAuth state, and retired signing keys are owned by the SDK bundle. The plugin maps those contracts to Shopware sales channels and keeps buyer-facing work inside Store API route boundaries. Run `bin/validate-ucp-store.sh <base-url> conformance` against a live lane for signed-request conformance when transport behavior changes.

## Native Agentic Discovery

Native agentic discovery is the storefront-facing operating manual for agents. It complements UCP: discovery tells an agent how the merchant wants the shop to be used, while UCP tells the agent which transactional capabilities are technically available.

The core reference in [shopware/shopware#17033](https://github.com/shopware/shopware/pull/17033) introduces the sales-channel file primitives used by trunk/current `6.7+`.

- `/agents.md`
- `/llms.txt`
- `/.well-known/ai-catalog.json`

On lanes with `SalesChannelFileDiscovery`, `SalesChannelFileRenderer`, and the `sales_channel_file` table, the plugin enables the `agentic` file family for active UCP sales channels and lets core render the files. On older lanes, the fallback bundle provides storefront routes for the same files. Fallback discovery is only rendered when UCP is active for the current sales channel; inactive channels receive `404`.

The generated documents are sales-channel scoped. They point agents at the UCP profile when UCP is active and describe safe shopping behavior for public storefront resources. Validate discovery after admin or route changes with a storefront browser check and direct `GET` requests for `/llms.txt`, `/agents.md`, and `/.well-known/ai-catalog.json` on each supported lane.

## Product Feed

The product-feed feature is the outbound catalog surface for agentic commerce consumers. It is separate from UCP catalog operations: UCP handles transactional runtime calls, while product feeds support catalog ingestion, indexing, and marketplace-style discovery.

Product feeds reuse Shopware's product export infrastructure on Agentic Commerce sales channels. The Administration registers two templates:

| Provider | Format | File format | Template source |
| --- | --- | --- | --- |
| `open-ai` | OpenAI product feed | JSONL, one JSON object per valid product row | `agentic-product-export-templates/open-ai/body.json.twig.js` |
| `google` | Google Merchant Center feed | XML RSS item feed | `agentic-product-export-templates/google/*.xml.twig.js` |

The templates are Twig, but they live in `.js` modules that export the template as a
string. They must reach Shopware byte-exact — the OpenAI feed is JSONL, so newlines are
significant — and every admin bundler treats a plain JS module identically, whereas an
imported `.twig` file gets its whitespace collapsed by Shopware's Twig loader.

The feed URL is the Shopware product export URL shown in the Agentic Commerce sales-channel detail. It must be publicly reachable by the feed consumer and is not signed by UCP. Product links in both feeds include `referringSalesChannel` and preserve configured affiliate/campaign codes so downstream orders and customers can be attributed to the Agentic Commerce channel.

OpenAI JSONL rows include search eligibility, checkout eligibility, item and offer identifiers, title, description, URL, image URLs, price, availability, brand/seller data, return policy URL, store/target countries, GTIN/MPN, digital-product signal, and variant data when variants are included. The JSONL renderer trims blank rows, skips empty product renders, re-encodes each row as one JSON object per line, and URL-encodes spaces in media URLs.

Google XML rows include the required Merchant Center fields, canonical and tracked product links, image URLs, availability, price/sale price, condition, brand, GTIN/MPN or `identifier_exists`, category path, item group, variant attributes, custom labels, and shipping data derived from the sales-channel context.

Feed generation, scheduling, caching, and invalidation are owned by Shopware's product export subsystem. The plugin supplies provider-specific templates, provider context, JSONL normalization, and validation. Template defaults set `generateByCronjob: false` and `interval: 86400`; merchants can adjust export behavior through the normal Shopware product export configuration.

## Local Development (plugin maintainers)

This section is about developing the plugin itself against three Shopware lanes. To run UCP on a shop you already have, see [Set up UCP on a sales channel](#set-up-ucp-on-a-sales-channel) above; the full lane workflow is in [docs/manual-testing.md](docs/manual-testing.md).

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
composer test              # unit suite (mocks, no kernel)
composer test:integration  # DB-backed integration suite (real connection, e.g. migrations)
composer test:functional   # functional suite (boots a real test kernel + Symfony browser)
bin/ci-smoke.sh /path/to/shopware-checkout
bin/ci-admin-smoke.sh /path/to/shopware-checkout auto
bin/ci-storefront-smoke.sh /path/to/shopware-checkout
```

`bin/ci-smoke.sh` resolves the SDK from Packagist at the versions `composer.json` pins. To smoke
a local SDK checkout instead, set `UCP_SDK_SOURCE=path` (and `SDK_ROOT`, which defaults to a
sibling `../ucp-php-sdk`); it is then staged into the shop and relabelled as the pinned version.

### Prefer functional tests over shell smoke

Cover behavior at the lowest layer that can express it, and reach for a PHP test
before a shell smoke check. The `functional` suite (`tests/Functional`,
`composer test:functional`) boots a real Shopware test kernel and drives UCP runtime
routes end-to-end through a real Symfony browser (against `APP_URL`, as Shopware's
own functional tests do), so it is the preferred home for route, request-context, and
capability behavior — it is readable and debuggable without a deployed HTTP
stack. It covers the request-context guards and the **catalog/cart/checkout
capability flows**, including completing a checkout into a **real Shopware order**
and reading it back via its context token (via the shared `UcpFlowTestBehaviour`).
It requires the booting bootstrap (`SHOPWARE_PROJECT_DIR` unset + `APP_ENV=test`) and,
like core, assumes a booted kernel — run it against a configured lane with
`composer test:functional`. In CI it gates on **every** `shopware-matrix` lane
(`CI_SMOKE_RUN_FUNCTIONAL=1`).

It runs on the **lane's own** phpunit: Shopware core's test base classes are
coupled to each lane's phpunit major (6.5→9.x, 6.6→10.x, trunk→11.x), so a single
pinned phpunit can't span lanes. `bin/run.php` prefers the platform phpunit inside
a lane and `tests/bootstrap.php` registers the plugin on the platform autoloader;
ci-smoke installs Shopware's dev deps so that binary is present. The unit/mock
suites stay on the plugin's pinned `.tools` phpunit (fast, lane-independent).

Shell smoke (`bin/ci-smoke.sh`) is reserved for what the functional suite is the wrong
layer for — genuine deployed-stack / on-the-wire concerns. After the capability flows
moved to the functional suite, what stays in smoke and why:

- the **outbound signed order webhook** — actually delivered to an external
  endpoint with `signature`/`signature-input`/`content-digest` headers (on-the-wire);
- **tokenize 501** — the payment endpoint needs a *signed* request;
- **profile/discovery** — lane-aware MCP transport detection + storefront-rendered
  `/llms.txt` and `/agents.md`;
- **admin/storefront** builds + UI shells (`bin/ci-admin-smoke.sh`,
  `bin/ci-storefront-smoke.sh`) — closer to a browser-e2e concern;
- **signed-request conformance** (`bin/validate-ucp-store.sh … conformance`).

The former `catalog` and `cart` smoke stages have been removed — that capability coverage now
lives entirely in the functional suite. The `checkout` stage resolves the seeded product itself
(one `catalog.lookup` as data setup) and stays in smoke only to drive the signed order webhook.
Almost any smoke assertion can become a functional test — when one can, move it and drop the
redundant smoke check once the functional suite gates in CI. See [AGENTS.md](AGENTS.md) for the
full layering rationale.

Manual human test steps are documented in [docs/manual-testing.md](docs/manual-testing.md).

Lane-specific administration, build, and local-runtime differences are documented in [docs/shopware-version-differences.md](docs/shopware-version-differences.md). Short-form guidance for future coding agents is kept in [AGENTS.md](AGENTS.md).

## Release

Store releases use `.github/workflows/store-release.yml`. Manual dispatch is safe by default: with `publish` disabled, the workflow builds and validates the same Shopware CLI package without uploading it. With `publish` enabled, it only releases the current `main` HEAD after that exact commit has a successful `validation-gate` check.

Prepare a release in a pull request by updating:

- `composer.json` (`version`), which is the Store release source of truth;
- `CHANGELOG.md` and `CHANGELOG_de-DE.md` with a matching `# <version>` section. Both files carry the same bullets in the same order, one to one; [AGENTS.md](AGENTS.md#changelog-entries) describes the entry format, and the 1.3.0 section is the reference shape.

After merging and waiting for the `main` CI run, dispatch a packaging-only run first. Enable `publish` only after that succeeds. Publishing uploads the ZIP to the Shopware Store and creates the version tag and GitHub release. It does not update the Store listing metadata or remove the Beta label.

Repository administrators must configure `SHOPWARE_CLI_ACCOUNT_CLIENT_ID` and `SHOPWARE_CLI_ACCOUNT_CLIENT_SECRET` as GitHub Actions secrets before publishing.

### The SDK version pin

The plugin requires `ucp-php-sdk/symfony-bundle` at the exact version it was tested against — currently `0.0.7`, though `composer.json` is the authority and this page is not. Not a caret, not a tilde, and not a `>=a <b` window. A caret on a `0.0.x` version already means that exact patch (`^0.0.2` is `>=0.0.2 <0.0.3`, which is why the plugin's original constraint never picked up `0.0.3`), `~0.0.6` expands to the open `>=0.0.6 <0.1.0`, and even `>=0.0.6 <0.0.7` still admits a four-component `0.0.6.1`. An exact version is the only constraint that cannot widen.

The pin is deliberate. A plugin has no `composer.lock` and the SDK resolves at merchant install time, so a range let any matching release — which carries no compatibility promise — reach production without a plugin change. The SDK serves exactly one UCP version per release and switches it outright (see the SDK's `docs/ucp-version-support-policy.md`), so an SDK release that moves the spec date changes what every shop advertises and has to arrive together with the plugin review that goes with it. The pin turns that into a release decision instead of an accident. [docs/ucp-version-support.md](docs/ucp-version-support.md) is the integrator-facing summary.

Moving the pin is a plugin release:

1. **Wait for the SDK tag to be published on Packagist.** `ucp-php-sdk/core` and `ucp-php-sdk/symfony-bundle` are public Packagist packages; the Store build and merchant installs resolve them from there. Do not merge plugin code that references symbols which only exist on the SDK `main` branch or an unmerged SDK PR — anyone who resolved before that tag existed gets the older release that lacks them, and the plugin fatals with `Class "…" not found`.
2. **Move the pin, and the forced versions with it.**
   - `composer.json` — the exact `ucp-php-sdk/core` and `ucp-php-sdk/symfony-bundle` versions. Both, not only the bundle: the bundle accepts a *range* of `core`, so pinning the bundle alone would let a later `core` release pair with it on a source install.
   - `.github/workflows/ci.yml` — the two forced `versions` in the *Configure private SDK path repositories* step (`ucp-php-sdk/core` and `ucp-php-sdk/symfony-bundle`). A forced version outside the pin no longer satisfies the constraint and resolution breaks.
   - `bin/ci-smoke.sh` — the same two forced `versions` in the `composer config repositories.ucp-sdk-*` lines, which apply only under `UCP_SDK_SOURCE=path`.
   - `src/Ucp/UcpProtocol.php` — only if the SDK release moved the spec date. `UcpProtocolVersionGuardTest` fails until `UcpProtocol::VERSION` follows, and it must follow only after `ShopwareDataMapper` and `UcpCapabilityCatalog` have been reviewed against the new schemas. Do not make the constant read the SDK's enum; the failing test is the point.
   - `CHANGELOG.md` and `CHANGELOG_de-DE.md`.
3. **Leave `UCP_SDK_REF` on `main`.** One job, `sdk-main-compatibility`, still builds the plugin against the moving SDK `main` branch so upcoming SDK breakage is caught early. Do not pin `UCP_SDK_REF` to a tag to "make CI match production" — that trades away the early-warning signal.

### Which SDK a CI job resolves

Every job that blocks a merge resolves both SDK packages **from Packagist at the versions `composer.json` pins** — the pair a merchant installs. `sdk-main-compatibility` is the single exception and the only job that stages an SDK checkout: it points a path repository at `UCP_SDK_REF` and relabels that source as the pinned version.

That relabelling is why the exception is `continue-on-error` and absent from both `expected_checks` and `validation-gate`. A branch wearing a release's version number is not what anyone installs, and when SDK `main` moved to require a newer `core`, having it on the merge path turned this repository's `main` red for five days — and would have done the same to every open pull request at once. Read the job, open an SDK issue; do not let it stop a merge.

`bin/ci-smoke.sh` follows the same rule through `UCP_SDK_SOURCE`, which defaults to `packagist`; only `sdk-main-compatibility` and local manual testing set `path`.

> **Why green CI is not enough on its own:** a change that compiles against SDK `main` in `sdk-main-compatibility` can still be broken against whichever published tag an install resolves. Before merging SDK-coupled code for a release, confirm the required symbols exist in a **published** SDK tag and that `composer.json` pins that tag.

### Migrations and releases

Shopware's migration runner tracks each migration by class + creation timestamp and **never re-executes one it has already marked applied**. This has a hard consequence for edits:

- **Never change the effect of a migration that has shipped in a tagged release.** Existing installs will not re-run it, so editing the DDL only affects fresh installs and silently drifts the schema of upgraded shops (e.g. a column added to a `CREATE TABLE IF NOT EXISTS` is a no-op where the table already exists). Add a **new** forward migration instead, made idempotent (guard column/index/FK changes with `information_schema` checks) so it is safe on both drifted and already-correct schemas.
- **Editing a migration that only exists in the current unreleased development cycle is fine.** No released install ever ran the old version, and fresh installs get the corrected DDL. Only internal dev/QA/CI shops that ran the intermediate version drift — reset or manually reconcile those databases rather than shipping a migration for them. Check with `git show <tag>:<migration-path>`: if the migration is absent from every release tag, editing it is safe.

### Test packages on pull requests

Reviewers can get an install-ready ZIP for a pull request without a local build. `.github/workflows/package-zip.yml` builds and validates the extension with the same `shopware/github-actions/build-zip` action the release uses, then uploads it as a `SwagAgenticCommerce.zip` run artifact.

The build is opt-in per PR to keep it off the default CI path:

1. Add the `build:zip` label to the pull request. The label triggers a build right away, and every later push rebuilds the ZIP while the label stays on. Remove the label to stop rebuilding.
2. Open the workflow run from the PR checks (or the Actions tab) and download `SwagAgenticCommerce.zip` from the run's **Artifacts**.
3. Install it in a Shopware shop via **Extensions → My extensions → Upload extension**, or with `bin/console plugin:install --activate` after unzipping into `custom/plugins`.

Create the `build:zip` label once under **Issues → Labels** (any color/description) if it does not exist yet; the workflow matches it by name.

Administration build compatibility is intentionally validated as a matrix:

- `6.5.x`: webpack only
- `6.6.x`: webpack and Vite
- `trunk` / current `6.7`: Vite only

The plugin handles this with one administration implementation and lane-aware build/test scripts, not by copying admin modules per Shopware line.

GitHub Actions checks out public `shopware/shopware` and public `agentic-commerce-alliance/ucp-php-sdk` directly. Both repositories are public, so no repository secret or access token is required for the SDK checkout.

The plugin stores tooling dependencies in `.tools/vendor`, not `vendor`, so lane-local Composer installs do not collide with the Shopware runtime dependency graph.

Runtime dependencies are installed through the active Shopware lane's root `composer.json`. The plugin's source `composer.json` is the public metadata source of truth for plugin-owned dependencies: it requires the public `ucp-php-sdk/symfony-bundle` Packagist package, and SDK core is resolved transitively by that bundle. Shopware packages are provided by the active lane. Release packages therefore do not embed a plugin-local vendor tree.

Local lanes and CI still configure path repositories for the public SDK checkout so compatibility can be tested against `UCP_SDK_REF` before an SDK release. Those path repositories force the SDK to a version that must satisfy the `composer.json` range — at or above its lower bound (see *Bumping the SDK version floor* above), and the plugin path repository exposes the release version from `composer.json` so Shopware's plugin lifecycle resolves the same package version during `plugin:install`.

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
