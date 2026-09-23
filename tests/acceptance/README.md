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

| Project           | Tag                | Notes                                                                |
| ----------------- | ------------------ | -------------------------------------------------------------------- |
| `Signer`          | `@Signer`          | RFC 9421 signer proofs. No browser. Every UCP project depends on it. |
| `Setup`           | `@Setup`           | The known-blockers guard and the bootstrap check.                    |
| `UcpProtocol`     | `@UcpProtocol`     | UCP transport journeys.                                              |
| `UcpContent`      | `@UcpContent`      | Product feed, tracking, discovery files.                             |
| `UcpEmbedded`     | `@UcpEmbedded`     | Embedded transport.                                                  |
| `UcpAdmin`        | `@UcpAdmin`        | Administration UI.                                                   |
| `UcpAcl`          | `@UcpAcl`          | ACL matrix.                                                          |
| `UcpSerial`       | `@UcpSerial`       | Runs with `workers: 1` for specs that cannot be parallelised.        |
| `UcpKnownBlocked` | `@UcpKnownBlocked` | **Non-gating.** See below.                                           |

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

Import from the fixture entry point and nothing else:

```ts
import { expect, test } from "@fixtures/AcceptanceTest";
```

`fixtures/AcceptanceTest.ts` calls `mergeTests(ShopwareTestSuite, ...)` and re-exports the package,
so a spec never has to know which file a fixture came from.

## Environment

Read by the ATS itself: `APP_URL`, `ADMIN_API_URL`, `ADMIN_URL`, `SHOPWARE_ACCESS_KEY_ID`,
`SHOPWARE_SECRET_ACCESS_KEY`, `SHOPWARE_ADMIN_USERNAME` (default `admin`), `SHOPWARE_ADMIN_PASSWORD`
(default `shopware`), `ATS_ID_SEED`, `ATS_SKIP_CLEANUP`, `MAILPIT_BASE_URL`,
`SHOPWARE_ACCEPTANCE_INSTANCE_TYPE`, `LANG`/`LANGUAGE`.

Read by this config: `SHOPWARE_PLAYWRIGHT_IGNORE_HTTPS_ERRORS`, `DATABASE_URL`, `CI`.

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

## Notes

- `npm install` warns that `skia-canvas` has an install script npm 11 does not run by default. It is
  a transitive dependency of the ATS's screenshot comparison. Nothing in this suite uses visual
  assertions today; approve the script with `npm install-scripts approve skia-canvas` if that
  changes.
