# Release

Store releases use `.github/workflows/store-release.yml`. Manual dispatch is safe by default: with
`publish` disabled, the workflow builds, validates and install-tests the package without uploading
it. With `publish` enabled, it only releases the current `main` HEAD after that exact commit has a
successful `validation-gate` check, and it uploads the same archive the install test used. Pushing a
pre-release tag runs the same workflow for a test build instead, see
[Pre-releases for testing](#pre-releases-for-testing).

Prepare a release in a pull request by updating:

- `composer.json` (`version`), which is the Store release source of truth;
- `CHANGELOG.md` and `CHANGELOG_de-DE.md` with a matching `# <version>` section.

After merging and waiting for the `main` CI run, dispatch a packaging-only run first. Enable
`publish` only after that succeeds. Publishing uploads the ZIP to the Shopware Store and creates the
version tag and GitHub release. It does not update the Store listing metadata or remove the Beta
label.

Repository administrators must configure `SHOPWARE_CLI_ACCOUNT_CLIENT_ID` and
`SHOPWARE_CLI_ACCOUNT_CLIENT_SECRET` as GitHub Actions secrets before publishing.

## Pre-releases for testing

Push a tag such as `v1.3.1-rc.1` to build a test package. It needs no change to `composer.json` or
the changelogs:

```bash
git tag v1.3.1-rc.1 <commit>
git push origin v1.3.1-rc.1
```

`store-release.yml` then builds the archive with `1.3.1-rc.1` as its version and runs the same
archive guards as a release. It installs the archive on 6.5.x, 6.6.x and trunk shops that have never
seen the UCP SDK, and attaches it to a GitHub release marked as a pre-release. This path never
uploads to the Store and uses no Store secrets. GitHub reads the workflow from the tagged commit, so
the commit must contain this workflow.

The tag must have the form `v<major>.<minor>.<patch>-<alpha|beta|rc>.<n>`. Only a tag with a hyphen
starts the workflow, so a release tag never does. A tag with a hyphen but the wrong form, such as
`1.3.1-rc.1` without the `v` or `v1.3.1-rc1`, fails with a message that shows the right one.
Composer and `shopware-cli` accept only the three suffixes: `1.4.0-ucptest1` fails
`extension validate`, and build metadata such as `1.3.1+test` is dropped on install, so the shop
would store the package as `1.3.1`. The workflow also refuses a base version that is already
released or older than the `composer.json` version on the tagged commit, because Shopware offers an
update only to a higher version. `1.3.1-rc.1` sorts above `1.3.0` and below `1.3.1`, so a test shop
moves from the release to the pre-release and later to the final release without an uninstall.

An installed pre-release stays recognisable. Shopware stores its version as `1.3.1-RC1`, and the
extension list shows the label `Agentic Commerce (pre-release 1.3.1-rc.1)`, or
`Agentic Commerce (Vorabversion 1.3.1-rc.1)` in German. The next plugin refresh after the final
release is installed restores the normal label.

> A published tag cannot be rebuilt, because its GitHub release already exists. Push the next number
> (`v1.3.1-rc.2`) rather than moving a tag.

## The SDK version pin

The plugin requires `ucp-php-sdk/symfony-bundle` at the exact version it was tested against —
currently `0.0.7`, though `composer.json` is the authority and this page is not. Not a caret, not a
tilde, and not a `>=a <b` window. A caret on a `0.0.x` version already means that exact patch
(`^0.0.2` is `>=0.0.2 <0.0.3`, which is why the plugin's original constraint never picked up
`0.0.3`), `~0.0.6` expands to the open `>=0.0.6 <0.1.0`, and even `>=0.0.6 <0.0.7` still admits a
four-component `0.0.6.1`. An exact version is the only constraint that cannot widen.

The pin is deliberate. A plugin has no `composer.lock` and the SDK resolves at merchant install
time, so a range let any matching release — which carries no compatibility promise — reach
production without a plugin change. The SDK serves exactly one UCP version per release and switches
it outright (see the SDK's `docs/ucp-version-support-policy.md`), so an SDK release that moves the
spec date changes what every shop advertises and has to arrive together with the plugin review that
goes with it. The pin turns that into a release decision instead of an accident.
[docs/ucp-version-support.md](ucp-version-support.md) is the integrator-facing summary.

Moving the pin is a plugin release:

1. **Wait for the SDK tag to be published on Packagist.** `ucp-php-sdk/core` and
   `ucp-php-sdk/symfony-bundle` are public Packagist packages; the Store build and merchant installs
   resolve them from there. Do not merge plugin code that references symbols which only exist on the
   SDK `main` branch or an unmerged SDK PR — anyone who resolved before that tag existed gets the
   older release that lacks them, and the plugin fatals with `Class "…" not found`.
2. **Move the pin, and the forced versions with it.**
   - `composer.json` — the exact `ucp-php-sdk/core` and `ucp-php-sdk/symfony-bundle` versions. Both,
     not only the bundle: the bundle accepts a _range_ of `core`, so pinning the bundle alone would
     let a later `core` release pair with it on a source install.
   - `.github/workflows/ci.yml` — the two forced `versions` in the _Configure private SDK path
     repositories_ step (`ucp-php-sdk/core` and `ucp-php-sdk/symfony-bundle`). A forced version
     outside the pin no longer satisfies the constraint and resolution breaks.
   - `bin/ci-smoke.sh` — the same two forced `versions` in the
     `composer config repositories.ucp-sdk-*` lines, which apply only under `UCP_SDK_SOURCE=path`.
   - `src/Ucp/UcpProtocol.php` — only if the SDK release moved the spec date.
     `UcpProtocolVersionGuardTest` fails until `UcpProtocol::VERSION` follows, and it must follow
     only after `ShopwareDataMapper` and `UcpCapabilityCatalog` have been reviewed against the new
     schemas. Do not make the constant read the SDK's enum; the failing test is the point.
   - `CHANGELOG.md` and `CHANGELOG_de-DE.md`.
3. **Leave `UCP_SDK_REF` on `main`.** One job, `sdk-main-compatibility`, still builds the plugin
   against the moving SDK `main` branch so upcoming SDK breakage is caught early. Do not pin
   `UCP_SDK_REF` to a tag to "make CI match production" — that trades away the early-warning signal.

## Which SDK a CI job resolves

Every job that blocks a merge resolves both SDK packages **from Packagist at the versions
`composer.json` pins** — the pair a merchant installs. `sdk-main-compatibility` is the single
exception and the only job that stages an SDK checkout: it points a path repository at `UCP_SDK_REF`
and relabels that source as the pinned version.

That relabelling is why the exception is `continue-on-error` and absent from both `expected_checks`
and `validation-gate`. A branch wearing a release's version number is not what anyone installs, and
when SDK `main` moved to require a newer `core`, having it on the merge path turned this
repository's `main` red for five days — and would have done the same to every open pull request at
once. Read the job, open an SDK issue; do not let it stop a merge.

`bin/ci-smoke.sh` follows the same rule through `UCP_SDK_SOURCE`, which defaults to `packagist`;
only `sdk-main-compatibility` and local manual testing set `path`.

> **Why green CI is not enough on its own:** a change that compiles against SDK `main` in
> `sdk-main-compatibility` can still be broken against whichever published tag an install resolves.
> Before merging SDK-coupled code for a release, confirm the required symbols exist in a
> **published** SDK tag and that `composer.json` pins that tag.

## Migrations and releases

Shopware's migration runner tracks each migration by class + creation timestamp and **never
re-executes one it has already marked applied**. This has a hard consequence for edits:

- **Never change the effect of a migration that has shipped in a tagged release.** Existing installs
  will not re-run it, so editing the DDL only affects fresh installs and silently drifts the schema
  of upgraded shops (e.g. a column added to a `CREATE TABLE IF NOT EXISTS` is a no-op where the
  table already exists). Add a **new** forward migration instead, made idempotent (guard
  column/index/FK changes with `information_schema` checks) so it is safe on both drifted and
  already-correct schemas.
- **Editing a migration that only exists in the current unreleased development cycle is fine.** No
  released install ever ran the old version, and fresh installs get the corrected DDL. Only internal
  dev/QA/CI shops that ran the intermediate version drift — reset or manually reconcile those
  databases rather than shipping a migration for them. Check with `git show <tag>:<migration-path>`:
  if the migration is absent from every release tag, editing it is safe.

## Test packages on pull requests

Every pull request gets an install-ready ZIP. The `zip-artifact` job in `ci.yml` calls
`.github/workflows/package-zip.yml`, which builds the extension the way a Store release does, checks
it with `bin/ci-assert-zip-admin-bundle.sh` and `bin/ci-assert-zip-no-vendor.sh`, installs it on
6.5.x, 6.6.x and trunk shops that have never seen the UCP SDK, and uploads it as the
`SwagAgenticCommerce` run artifact.

1. Open the pull request's `zip-artifact` check, go to the run's **Summary**, and download
   `SwagAgenticCommerce` from **Artifacts**. Artifacts are kept for 14 days.
2. Install it in a Shopware shop via **Extensions → My extensions → Upload extension**, or with
   `bin/console plugin:install --activate` after unzipping into `custom/plugins`.

The build keeps the version from `composer.json` and appends the pull request number to the
extension label, for example `Agentic Commerce (PR #256)`, so an installed copy is not mistaken for
the release. The same run also carries `SwagAgenticCommerce-archive`, the archive the install and
publish jobs use. A browser download of it arrives zipped twice, so ignore it.

To package any other branch or tag, run the **Package extension zip** workflow by hand.

## Dependencies in a release

Runtime dependencies are installed through the active Shopware lane's root `composer.json`. The
plugin's source `composer.json` is the public metadata source of truth for plugin-owned
dependencies: it requires the public `ucp-php-sdk/core` and `ucp-php-sdk/symfony-bundle` Packagist
packages at an exact version. Shopware packages are provided by the active lane.

A store release resolves them the same way, just later: the plugin returns true from
`executeComposerCommands()`, so Shopware runs `composer require shopware/agentic-commerce:<version>`
against the project when the archive is installed or updated. Every Shopware project declares
`custom/plugins/*` as a path repository, so that resolves the extracted archive locally and pulls
the pinned SDK from Packagist into the shop's own `vendor/`. The archive ships no dependencies of
its own -- `bin/ci-assert-zip-no-vendor.sh` fails the build if any appear, because a plugin-local
`vendor/autoload.php` registers a second Composer `ClassLoader` in the shop. The trade-off is that a
zip install has to reach Packagist.

An update extracts the new files one request before Shopware runs Composer, so an active plugin
boots at least once with its dependency missing, or with the previously pinned SDK still installed.
`SdkAvailability` decides that question -- comparing the installed version against the one
`composer.json` pins, not merely whether the classes load -- and when the answer is no,
`services.php` and `routes.php` return without registering anything and `build()` writes the reason
and the fix to the shop's `var/log/swag-agentic-commerce.log`. The plugin contributes nothing to the
container rather than half of it, which is what keeps the storefront answering while Composer
catches up.

Only the `sdk-main-compatibility` job and local smoke runs with `UCP_SDK_SOURCE=path` use path
repositories for the SDK, see [Which SDK a CI job resolves](#which-sdk-a-ci-job-resolves).
