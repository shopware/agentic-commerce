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
| `npm run test:signer`                | only `Signer`, without a shop.                                  |
| `npm run lint` / `npm run typecheck` | ESLint and `tsc --noEmit`.                                      |

`APP_URL` is required; the config exits with the list of missing variables rather than failing later
with an opaque error. `npm run test:signer` is the exception: it sets `UCP_SIGNER_ONLY=1`, which
drops the check and the wait for the server.

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
- When a known blocker breaks a case only on some versions, the gating spec skips that range and
  names the blocker, and a spec in `tests/KnownBlocked/` asserts the same behaviour on exactly that
  range. The ranges live in `tests/UcpContent/knownBlockerRanges.ts`.
- Unconditional skips are not allowed.
- `test.fixme()` marks a genuinely unimplemented dependency.

Version comparisons use `compare-versions` (`satisfies`), which the ATS already depends on, against
`InstanceMeta.version`.

## Writing a spec

Import from the fixture entry point and nothing else:

```ts
import { expect, test } from "@fixtures/AcceptanceTest";
```

`fixtures/AcceptanceTest.ts` calls `mergeTests(ShopwareTestSuite, ...)` and re-exports the package,
so a spec never has to know which file a fixture came from.

`Signer` specs are the exception. They import `test` and `expect` from `@playwright/test`, so no ATS
fixture reaches for a shop that the server-free run does not have.

## Fixtures

Every Playwright worker owns its test data. The ATS `DefaultSalesChannel` fixture gives each worker
a storefront-type sales channel on the path-prefixed domain `${APP_URL}test-<uuid>/`, so the
Administration shows the Agentic Commerce tab for it. The plugin fixtures build on the worker
channel.

**`TestDataService`** (test scope) is `UcpTestDataService`, the ATS `TestDataService` plus UCP:
`createStorefrontSalesChannel()`, `createHeadlessSalesChannel()`, `createFeedSalesChannel()`,
`activateUcp()`, `saveUcpConfig()`, `getUcpConfig()`, `getProfilePreview()`,
`listUcpSalesChannels()`, `createProductFeed()` and `saveFeedSettings()`. `createProductFeed()`
builds a feed channel with a product export over the given products for the worker channel, from a
template `readFeedTemplates()` reads out of the shipped Administration modules, with `interval: 0`
so every request renders the current products. `activateUcp()` enables every capability and the REST
transport and allowlists the agent profile host on all three per-channel lists, because the SDK
falls back to the shop's own host for an empty list and would refuse a `localhost` profile. Its
cleanup runs before the ATS registry's. It restores the UCP config each surviving channel had before
the first write, deletes the signing keys of the channels it created and activated when `UcpConsole`
reaches `bin/console`, and deletes the channels it created. Every step runs even when an earlier one
fails, and the failures are reported together. The ATS registry's cleanup is retried once, because
MariaDB 11.6.2 and newer reject a product delete with error 1020 while other workers write products.

Every provider a shipped feed template registers needs an entry in
`tests/UcpContent/feedProviders.ts`: its field names, its parser, the settings it needs and one
set-up its validator must reject. The feed specs run once per entry.
`tests/Setup/feed-provider-coverage.spec.ts` fails while a template has no entry, and the PHPUnit
test `EveryProviderShipsATemplateTest` fails while a provider class has no template or validator.

**`UcpAgentProfileHost`** (worker scope) has `publish()`, which generates an ES256 key pair and
writes the agent's profile, public key in `keys` included, to
`<SHOPWARE_DIR>/public/ucp-acceptance-agents/<kid>.json`. The agent sends it as
`UCP-Agent: profile="<url>"`. The profile carries every shopping capability the UCP specification
defines at the protocol version, so the SDK has something to negotiate, and
`publish({ capabilities })` replaces that set for negative specs. The shop fetches the profile from
`UCP_AGENT_PROFILE_BASE_URL` (default `http://localhost:8000`), the web container's own document
root, the only plain-http host the SDK admits and only in development mode. The fixture verifies the
file is served through `APP_URL` and throws otherwise. It never falls back to the shop's own
profile. A worker removes its own files at teardown.

**`UcpAclUsers`** (test scope) has `as('ucp.viewer' | 'ucp.editor' | 'ucp.key_rotator')`, which
creates an ACL role and a non-admin user, logs into the Administration in a separate page context
and returns it. The privilege sets are read from
`src/Resources/app/administration/src/extension/sw-sales-channel/acl/index.js`, with core's
`sales_channel.viewer` set added so the user can reach the sales channel at all.

**`UcpConsole`** (worker scope) runs `ucp:signing-keys:{generate,list,show-public,retire,delete}`,
the only signing-key management surface, through the lane's `bin/console`. Nothing else passes its
allow-list. Before the first command it probes the real prefix once. When that probe fails, the key
cleanup is skipped with a warning naming the sales channels whose keys stay behind, and the
`@UcpConsole` spec fails.

**`UcpAgent`** and **`UcpAgentB`** (worker scope) are two external shopping agents, each with its
own key pair and published profile. They do not extend the ATS `Actor`, which is bound to a browser
page, so protocol specs run without Chromium. Both talk to `APP_URL` until `at(url)` points them at
a sales channel. `rest.send(method, path, options)` signs a REST call, and `a2a.call()` and
`mcp.call()` wrap a signed JSON-RPC 2.0 request for `/ucp/a2a` and `/ucp/mcp`. `unsigned` sends
`UCP-Agent` without a signature, and `tampered(knobs)` signs and then breaks the request. `signs()`
returns the signed request without sending it. Every `POST`, `PUT`, `PATCH` and `DELETE` gets a
fresh `Idempotency-Key` unless the call passes one, or `false` for none.

The lane has to run with `SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE=1` for the
shop to fetch a test agent's profile from `localhost` over plain http.

### Signing

`services/UcpSigner.ts` signs per [RFC 9421](https://www.rfc-editor.org/rfc/rfc9421.html) with
ES256, following the [UCP signatures specification](https://ucp.dev/specification/signatures/) and
not the plugin's implementation. `services/UcpAgentKeyring.ts` holds the keys on Node's WebCrypto.

- The covered components are `@method`, `@authority`, `@path`, `@query` when the URL has a query,
  `ucp-agent`, `signature-agent` when present, `idempotency-key` on `POST`, `PUT`, `PATCH` and
  `DELETE` when present, then `content-digest` and `content-type` when there is a body.
- The parameters are `created` and `keyid`, under the label `sig1`. The spec makes `created`
  optional, but the SDK rejects a signature without it (known blocker D1), so the signer always
  sends it.
- The body is serialised once. The `sha-256` digest is taken over that buffer, and that buffer is
  sent.
- An ES256 signature is the raw 64-byte `r||s`. The keyring throws on any other length.

Negative specs break a request through `SignatureKnobs`, never by hand: `tamperBody`,
`tamperSignatureBytes`, `replay`, `label`, `includeTargetUri`, `omitCreated`, `createdOffsetSeconds`
(`-400` is stale, `+400` lies in the future), `withAlg` and `withExpires`.
`tests/Signer/knobs.spec.ts` proves that each knob changes the signature base, the signature or the
body. A knob that changes nothing would turn every spec that uses it into a false pass.

`tests/Signer/rfc9421-vectors.spec.ts` holds the known answers from RFC 9421 Appendix B. It rebuilds
the full-coverage request base byte for byte. It reproduces the Ed25519 request signature over the
base the signer builds, since Ed25519 is deterministic. It also verifies the published P-256
signature with the published key. The UCP specification's own examples cannot serve as vectors,
because they carry no key and truncate the digest and signature values.

### What the fixtures need from their host

Beyond `APP_URL`, two fixtures touch the Shopware project directly. Each resolves what it needs from
an environment variable when it is first used, and fails loudly when it cannot:

| Variable       | Default                                                                                                     | Needed by                                                                          |
| -------------- | ----------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `SHOPWARE_DIR` | the nearest ancestor of this directory with a `bin/console`                                                 | `UcpAgentProfileHost` writes into its `public/`; `UcpConsole` runs there           |
| `PLUGIN_DIR`   | the checkout this suite sits in, else the `custom/plugins` directory that holds `shopware/agentic-commerce` | reading `UcpProtocol::VERSION`, the Administration ACL file and the feed templates |
| `UCP_CONSOLE`  | `docker compose exec -T web php bin/console`                                                                | `UcpConsole`                                                                       |

A runner that reaches Shopware only over HTTP can run every spec that uses neither fixture. The
profile host and the console need the project mounted.

`UCP_CONSOLE` is executed directly, not through a shell, so it cannot see shell aliases. On a
machine where `docker` is only an alias for `podman`, the default prefix is not found and the
signing keys of created channels are left behind. Set `UCP_CONSOLE` to a command that reaches the
web container without an alias.

## Environment

Read by the ATS itself: `APP_URL`, `ADMIN_API_URL`, `ADMIN_URL`, `SHOPWARE_ACCESS_KEY_ID`,
`SHOPWARE_SECRET_ACCESS_KEY`, `SHOPWARE_ADMIN_USERNAME` (default `admin`), `SHOPWARE_ADMIN_PASSWORD`
(default `shopware`), `ATS_ID_SEED`, `ATS_SKIP_CLEANUP`, `MAILPIT_BASE_URL`,
`SHOPWARE_ACCEPTANCE_INSTANCE_TYPE`, `LANG`/`LANGUAGE`.

Read by this config: `SHOPWARE_PLAYWRIGHT_IGNORE_HTTPS_ERRORS`, `DATABASE_URL`, `CI`,
`UCP_SIGNER_ONLY`.

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
