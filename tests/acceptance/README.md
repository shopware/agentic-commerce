# Acceptance tests

End-to-end tests for `SwagAgenticCommerce`, built on Shopware's
[Acceptance Test Suite](https://www.npmjs.com/package/@shopware-ag/acceptance-test-suite) (ATS), the
Playwright-based package core, `SwagPayPal` and `SwagCommercial` use.

This is a **nested npm project**. It is deliberately not hoisted into the plugin's root
`package.json`, for three reasons:

- the ATS pins `@playwright/test` and `playwright-core` as **exact** peer dependencies (`1.62.1`),
  while the root declares `^1.55.0`;
- the ATS requires Node 24, while the root project and three CI jobs ran on Node 22;
- the root `eslint.config.mjs` loads Shopware's Administration rules from `ADMIN_PATH`, which do not
  apply to Playwright TypeScript.

## Running it

```bash
nvm use                       # Node 24, pinned in .nvmrc
npm install
npx playwright install chromium
```

Point it at a running Shopware. Nothing is started for you: the `webServer` block waits for an
externally started instance.

```bash
export APP_URL=http://localhost:8000/
export SHOPWARE_PLAYWRIGHT_IGNORE_HTTPS_ERRORS=1   # only when the instance is self-signed
npm test
```

| Script                               | What it runs                                                    |
| ------------------------------------ | --------------------------------------------------------------- |
| `npm test`                           | every project except `UcpKnownBlocked`. This is the gating run. |
| `npm run test:known-blocked`         | only `UcpKnownBlocked`. Reported, never gating.                 |
| `npm run test:all`                   | everything, gating and non-gating together.                     |
| `npm run lint` / `npm run typecheck` | ESLint and `tsc --noEmit`.                                      |

`APP_URL` is required; the config exits with the list of missing variables rather than failing later
with an opaque error.

## Projects and tags

Projects are routed by tag, the way core does it. A spec opts into a project by carrying its tag in
the title.

| Project           | Tag                | Notes                                                                                                                            |
| ----------------- | ------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| `Signer`          | `@Signer`          | RFC 9421 signer proofs. No browser. Every UCP project depends on it.                                                             |
| `Setup`           | `@Setup`           | The known-blockers guard, the bootstrap check and the fixture proofs. `@UcpConsole` marks the one spec that needs `bin/console`. |
| `UcpProtocol`     | `@UcpProtocol`     | UCP transport journeys.                                                                                                          |
| `UcpContent`      | `@UcpContent`      | Product feed, tracking, discovery files.                                                                                         |
| `UcpEmbedded`     | `@UcpEmbedded`     | Embedded transport.                                                                                                              |
| `UcpAdmin`        | `@UcpAdmin`        | Administration UI.                                                                                                               |
| `UcpAcl`          | `@UcpAcl`          | ACL matrix.                                                                                                                      |
| `UcpSerial`       | `@UcpSerial`       | Runs with `workers: 1` for specs that cannot be parallelised.                                                                    |
| `UcpKnownBlocked` | `@UcpKnownBlocked` | **Non-gating.** See below.                                                                                                       |

Most projects match no specs yet; their issues add them. A project with no matching spec reports
zero tests and passes.

### `UcpKnownBlocked`

A spec belongs here when it asserts what the UCP specification requires, is correct as written, and
fails only because the plugin or the SDK currently deviates. It still runs and still goes red; it
just does not block the pull request.

The deviations live in [`known-blockers.ts`](known-blockers.ts), each with an owner, an issue URL
and a review date. `tests/Setup/known-blockers.spec.ts` fails the build when an entry loses its
issue link or outlives its review date, so this project cannot quietly turn into a place where
failures are hidden.

Never pin a deviation as the expected result. A known deviation is a red spec with an owner, not a
green spec asserting the wrong thing.

## Skip rule

`test.skip(condition, reason)` is allowed **only** when a Shopware version genuinely lacks the
feature, and the reason must name the version boundary:

```ts
test.skip(satisfies(InstanceMeta.version, "<6.7.1.0"), "robots.txt arrived in 6.7.1.0");
```

- A misbehaviour on a version is a **failing test**, never a skip.
- Unconditional skips are not allowed.
- `test.fixme()` marks a genuinely unimplemented dependency.

Version comparisons use `compare-versions` (`satisfies`), which the ATS already depends on, against
`InstanceMeta.version`.

## Writing a spec

Import fixtures from the fixture entry point and nothing else:

```ts
import { expect, test } from "@fixtures/AcceptanceTest";
```

Page objects for the plugin's Administration live in `page-objects/` (`@page-objects/*`). They locate
the plugin's controls by their `data-testid` hooks (`ac-<area>__<element>[--<key>]`) and open routes
with `goToAdminRoute()`, which navigates inside the running Administration like the ATS `goesTo()`.

`fixtures/AcceptanceTest.ts` calls `mergeTests(ShopwareTestSuite, ...)` and re-exports the package,
so a spec never has to know which file a fixture came from.

## Fixtures

Every Playwright worker owns its test data. The ATS `DefaultSalesChannel` fixture gives each worker
a storefront-type sales channel on the path-prefixed domain `${APP_URL}test-<uuid>/`, so the
Administration shows the Agentic Commerce tab for it. That domain does not give the worker a profile
of its own yet: `/.well-known/ucp` under a prefixed domain resolves another channel (known blocker
D8). A spec that needs the real document creates a channel on a host of its own instead, see
`createStorefrontSalesChannelOnOwnHost()` below. The plugin fixtures build on the worker channel.

**`TestDataService`** (test scope) is `UcpTestDataService`, the ATS `TestDataService` plus UCP:
`createStorefrontSalesChannel()`, `createHeadlessSalesChannel()`, `createFeedSalesChannel()`,
`activateUcp()`, `saveUcpConfig()`, `getUcpConfig()`, `getProfilePreview()` and
`listUcpSalesChannels()`. For the Administration journeys it also has:

- `createStorefrontSalesChannelOnOwnHost()`: a channel whose only domain is the root of
  `c<id>.ucp-acceptance.test` on the `APP_URL` scheme and port, so its real `/.well-known/ucp` serves
  it. Only a browser resolves that host (`--host-resolver-rules` on the `Setup` and `UcpAdmin`
  projects maps it onto the `APP_URL` host), so read it with `fetchDiscoveryDocument()` from
  `services/discovery.ts`. The document is publicly cacheable for 60 seconds: read it after the
  change under test, not before.
- `createFeedSalesChannelWithExport(provider)`: a product-feed channel with a product export built
  from the plugin's shipped template, so the Administration finds nothing to align on load.
- `attributeOrderToFeedChannel()` and `attributeCustomerToFeedChannel()`: the tracking rows the
  storefront's `referringSalesChannel` tracking would write.
- `getSalesChannelReadback()`, `getChannelSystemConfig()`, `setChannelSystemConfig()`,
  `createProductStream()`, `markUcpActivated()` for a channel activated through the UI, and
  `adoptSalesChannel()` for one created through the UI, so the cleanup still covers them.

`activateUcp()` enables every capability and the REST transport and
allowlists the agent profile host on all three per-channel lists, because the SDK falls back to the
shop's own host for an empty list and would refuse a `localhost` profile. Its cleanup runs before
the ATS registry's. It restores the UCP config each surviving channel had before the first write,
deletes the signing keys of the channels it created and activated when `UcpConsole` reaches
`bin/console`, and deletes the channels it created. Every step runs even when an earlier one fails,
and the failures are reported together.

**`UcpAgentProfileHost`** (worker scope) has `publish()`, which generates an ES256 key pair and
writes the agent's profile, public key included, to
`<SHOPWARE_DIR>/public/ucp-acceptance-agents/<kid>.json`. The profile carries every shopping
capability the UCP specification defines at the protocol version, so the SDK has something to
negotiate, and `publish({ capabilities })` replaces that set for negative specs. The shop fetches
the profile from `UCP_AGENT_PROFILE_BASE_URL` (default `http://localhost:8000`), the web container's
own document root, the only plain-http host the SDK admits and only in development mode. The fixture
verifies the file is served through `APP_URL` and throws otherwise. It never falls back to the
shop's own profile. A worker removes its own files at teardown.

**`UcpAclUsers`** (test scope) has `as('ucp.viewer' | 'ucp.editor' | 'ucp.key_rotator')`, which
creates an ACL role and a non-admin user, logs into the Administration in a separate page context
and returns it. The role keys come from
`src/Resources/app/administration/src/extension/sw-sales-channel/acl/index.js`, with core's
`sales_channel.viewer` added so the user can reach the sales channel at all. The privileges are what
the running Administration's privileges service grants for those keys, as the role editor would store
them, so privileges other plugins add to core roles are included. `asSalesChannelViewer()` logs in a
user with only `sales_channel.viewer`. `asFreshAdmin()` logs in a full admin of the test's own, for
specs that change what the Administration stores per user, such as list filters; the worker's
`AdminPage` user is shared with every test of the worker.

**`UcpConsole`** (worker scope) runs `ucp:signing-keys:{generate,list,show-public,retire,delete}`,
the only signing-key management surface, through the lane's `bin/console`. Nothing else passes its
allow-list. Before the first command it probes the real prefix once. When that probe fails, the key
cleanup is skipped with a warning naming the sales channels whose keys stay behind, and the
`@UcpConsole` spec fails.

The lane has to run with `SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE=1` for the
shop to fetch a test agent's profile from `localhost` over plain http.

### What the fixtures need from their host

Beyond `APP_URL`, two fixtures touch the Shopware project directly. Each resolves what it needs from
an environment variable when it is first used, and fails loudly when it cannot:

| Variable       | Default                                                                                                     | Needed by                                                                |
| -------------- | ----------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `SHOPWARE_DIR` | the nearest ancestor of this directory with a `bin/console`                                                 | `UcpAgentProfileHost` writes into its `public/`; `UcpConsole` runs there |
| `PLUGIN_DIR`   | the checkout this suite sits in, else the `custom/plugins` directory that holds `shopware/agentic-commerce` | reading `UcpProtocol::VERSION` and the Administration ACL file           |
| `UCP_CONSOLE`  | `docker compose exec -T web php bin/console`                                                                | `UcpConsole`                                                             |

A runner that reaches Shopware only over HTTP can run every spec that uses neither fixture. The
profile host and the console need the project mounted.

Channels on a host of their own need the web server to answer any `Host` (a catch-all vhost) and
`TRUSTED_HOSTS`, when set, to admit `*.ucp-acceptance.test`. The `@Setup` spec "Channels on a host
of their own" fails with that message when the lane does not.

`UCP_CONSOLE` is executed directly, not through a shell, so it cannot see shell aliases. On a
machine where `docker` is only an alias for `podman`, the default prefix is not found and the
signing keys of created channels are left behind. Set `UCP_CONSOLE` to a command that reaches the
web container without an alias.

## Environment

Read by the ATS itself: `APP_URL`, `ADMIN_API_URL`, `ADMIN_URL`, `SHOPWARE_ACCESS_KEY_ID`,
`SHOPWARE_SECRET_ACCESS_KEY`, `SHOPWARE_ADMIN_USERNAME` (default `admin`), `SHOPWARE_ADMIN_PASSWORD`
(default `shopware`), `ATS_ID_SEED`, `ATS_SKIP_CLEANUP`, `MAILPIT_BASE_URL`,
`SHOPWARE_ACCEPTANCE_INSTANCE_TYPE`, `LANG`/`LANGUAGE`.

Read by this config: `SHOPWARE_PLAYWRIGHT_IGNORE_HTTPS_ERRORS`, `DATABASE_URL`, `CI`.

Read by the plugin fixtures: `SHOPWARE_DIR`, `PLUGIN_DIR`, `UCP_AGENT_PROFILE_BASE_URL`,
`UCP_CONSOLE`, `UCP_CONSOLE_TIMEOUT_MS`, `ATS_SKIP_CLEANUP`.

> `DATABASE_URL` is parsed into `ATS_DATABASE_USERNAME`/`_PASSWORD`/`_HOST`/`_NAME` for parity with
> core's configuration, but **nothing consumes those variables today**. ATS 12.20.0 ships no
> database driver and reaches Shopware only over the Admin API, the Store API and Mailpit. Do not go
> looking for a database connection that is never opened.

A `.env` file in this directory is loaded automatically and is gitignored.

## Test data left behind

The ATS `DefaultSalesChannel` fixture is worker-scoped with no teardown: it upserts a sales channel,
category, customer group, domain and customer under **worker-derived stable ids**, so repeat runs
reuse the same records rather than accumulating new ones. Expect one `<id> acceptance test` sales
channel per parallel worker to remain in the shop after a run. Changing `ATS_ID_SEED` produces a new
set.

Activating UCP on a channel auto-provisions a signing key in the SDK's key store, and on a Shopware
that ships the sales-channel file subsystem it also enables the agentic files for that channel. The
worker channel keeps both. Signing keys of the channels the suite created are removed only when
`UcpConsole` can reach `bin/console`; otherwise they outlive their channel.

## Notes

- `npm install` warns that `skia-canvas` has an install script npm 11 does not run by default. It is
  a transitive dependency of the ATS's screenshot comparison. Nothing in this suite uses visual
  assertions today; approve the script with `npm install-scripts approve skia-canvas` if that
  changes.
