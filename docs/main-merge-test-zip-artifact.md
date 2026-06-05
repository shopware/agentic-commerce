# Main-Merge Test Zip Artifact

This document describes how `SwagAgenticCommerce` produces one self-contained
tester zip after changes are merged to `main`.

The archive is for tester distribution while the SDK packages are private or
not reliably installable by testers. It is not the final store-ready release
strategy.

## Output

Successful `main` and manual workflow runs publish one GitHub Actions artifact:

```text
swag-agentic-commerce-test-zip
```

It contains:

```text
SwagAgenticCommerce-main-<short-sha>.zip
SwagAgenticCommerce-main-<short-sha>.zip.sha256
artifact-metadata.json
```

There must not be separate zips for `6.5.x`, `6.6.x`, and `trunk`. If one lane
fails, fix shared compatibility or packaging layout.

## Workflow

The package flow is in `.github/workflows/ci.yml` and runs on:

- `push` to `main`
- `workflow_dispatch`

It is skipped for pull requests.

The flow has three jobs:

| Job | Purpose |
| --- | --- |
| `package-test-zip` | Builds the candidate zip from a prepared staging directory. |
| `zip-install-smoke` | Installs the candidate zip on `6.5.x`, `6.6.x`, and `trunk`. |
| `publish-test-zip` | Publishes the final tester artifact after all zip-install smoke jobs pass. |

`package-test-zip` depends on the existing validation jobs:

- `php-quality`
- `admin-static`
- `shopware-matrix`
- `admin-matrix`
- `storefront-matrix`

## Package Job Steps

The package job:

1. Checks out the plugin into `agentic-commerce`.
2. Checks out `shopware/shopware` trunk/current 6.7 into `shopware`.
3. Checks out `shopware/ucp-php-sdk` into `ucp-php-sdk`.
4. Installs PHP and Shopware CLI.
5. Writes the CI Compose file for trunk.
6. Runs trunk Vite admin smoke with `CI_ADMIN_EXPORT_PLUGIN_PUBLIC`.
7. Runs `bin/ci-package-test-zip.sh`.
8. Uploads a short-lived candidate artifact.

The admin export captures the Vite-built package asset set from:

```text
custom/plugins/SwagAgenticCommerce/src/Resources/public/
```

## Package Script

The archive is built by:

```bash
bin/ci-package-test-zip.sh "$GITHUB_WORKSPACE/test-zip"
```

Important inputs:

| Variable | Default | Purpose |
| --- | --- | --- |
| `SDK_ROOT` | `../ucp-php-sdk` | SDK checkout used as Composer path repositories. |
| `ADMIN_PUBLIC_SOURCE` | `var/package-admin-public` | Vite-built plugin public assets. |
| `PACKAGE_VERSION` | `0.0.1+<short-sha>` | Version written into the packaged `composer.json`. |
| `UCP_SDK_REF` | empty | Recorded in metadata. |
| `SHOPWARE_65_REF` | empty | Recorded in metadata. |
| `SHOPWARE_66_REF` | empty | Recorded in metadata. |
| `SHOPWARE_TRUNK_REF` | empty | Recorded in metadata. |

The script stages a clean `SwagAgenticCommerce` directory and excludes:

- `.git`
- `.github`
- `.claude`
- `.tools`
- `.DS_Store`
- `._*`
- `.eslintcache`
- `.phpunit.cache`
- `.phpunit.result.cache`
- `composer.lock`
- `coverage`
- `node_modules`
- `public/bundles`
- `src/Resources/public`
- `tests`
- `var`
- `vendor`

It copies the exported admin assets into `src/Resources/public/`, copies the
legacy bootstrap into `src/Resources/public/administration/js/`, and writes:

```text
.swag-agentic-commerce-bundled-sdk
```

## Bundled SDK

The staged package installs runtime dependencies into plugin-local `vendor/`,
not `.tools/vendor`.

The temporary Composer file requires only PHP and:

```text
ucp-php-sdk/symfony-bundle
```

It configures path repositories to:

```text
<SDK_ROOT>/packages/core
<SDK_ROOT>/packages/symfony-bundle
```

with `symlink: false`, so the zip contains real package files:

```text
vendor/shopware/ucp-php-sdk-core
vendor/ucp-php-sdk/symfony-bundle
vendor/autoload.php
```

The temporary Composer file removes dev requirements and replaces
Shopware-provided Symfony/Doctrine packages so the packaged `vendor/` contains
the SDK only, not framework packages already provided by Shopware.

## Runtime Switch

`executeComposerCommands()` applies on every supported lane: `6.5.x`, `6.6.x`,
and `trunk/current 6.7`.

Normal source installs have no bundled SDK marker, so the plugin keeps:

```php
executeComposerCommands() === true
```

The tester zip has:

```text
.swag-agentic-commerce-bundled-sdk
```

For that artifact:

```php
executeComposerCommands() === false
```

This prevents Shopware from trying to run root Composer commands during zip
installation. Testers do not need SDK path repositories or SDK Composer
credentials.

`getAdditionalBundles()` loads plugin-local `vendor/autoload.php` when the
marker exists before checking for `Ucp\Sdk\Symfony\UcpSdkBundle`. Route loading
uses the bundled SDK path when the marker exists and Composer metadata
otherwise.

## Shopware CLI

The final archive is created with:

```bash
shopware-cli extension zip "$stage_dir" "main-<short-sha>" \
  --disable-git \
  --release \
  --overwrite-version "0.0.1+<short-sha>" \
  --filename "SwagAgenticCommerce-main-<short-sha>.zip" \
  --output-directory "$OUTPUT_DIR"
```

The command runs against the prepared staging directory, not the raw checkout.
The SDK is already installed before Shopware CLI runs; Shopware CLI handles the
archive, cleanup, release, and version-overwrite step.

`.shopware-extension.yml` constrains packaging/build behavior for Shopware CLI.
It must not add `shopware/core` to the plugin runtime `require` section.

## Asset Strategy

The tester archive ships one Vite-built administration asset set.

Older admin runtimes continue to rely on the legacy public bootstrap that reads:

```text
src/Resources/public/administration/js/swag-agentic-commerce.js
src/Resources/public/administration/.vite/entrypoints.json
```

The source bootstrap lives under
`src/Resources/app/administration/src/public/js/swag-agentic-commerce.js`.
The package script copies it into `src/Resources/public` because zip installs
run `assets:install` against that directory and do not rebuild administration
assets.

Required source validation remains:

| Lane | Required admin validation |
| --- | --- |
| `6.5.x` | webpack |
| `6.6.x` | webpack and Vite |
| `trunk` / current `6.7` | Vite |

If the packaged Vite asset set fails on `6.5.x` or `6.6.x`, fix the bootstrap
or package layout. Do not split the artifact into lane-specific zips.

## Zip-Install Smoke

`zip-install-smoke` downloads the candidate artifact and runs once per lane:

- `6.5.x`
- `6.6.x`
- `trunk`

Each run:

1. Checks out the plugin for test scripts and Playwright tests.
2. Checks out the matching Shopware lane.
3. Downloads the candidate zip.
4. Runs `bin/ci-smoke.sh` with `CI_SMOKE_PLUGIN_ZIP`.
5. Runs the admin Playwright suite.
6. Tears down the stack.

Zip mode in `bin/ci-smoke.sh`:

- unzips into `custom/plugins/SwagAgenticCommerce`
- does not copy `custom/ucp-php-sdk`
- unsets old SDK/plugin Composer path repositories
- does not require the plugin through the Shopware root Composer project
- runs `plugin:refresh`
- runs `plugin:install --activate SwagAgenticCommerce`
- runs `bundle:dump`, `feature:dump`, and `assets:install`
- asserts the packaged Vite entrypoints and legacy bootstrap were published to
  `public/bundles/swagagenticcommerce`
- validates `/.well-known/ucp`

## Artifact Assertions

The package script asserts the archive contains:

- SDK core sources
- SDK Symfony bundle sources
- `vendor/autoload.php`
- `.swag-agentic-commerce-bundled-sdk`
- `src/Resources/public/administration/.vite/entrypoints.json`
- `src/Resources/public/administration/js/swag-agentic-commerce.js`

It asserts the archive excludes:

- `.git`
- `.github`
- `.claude`
- `.tools`
- `.eslintcache`
- `.phpunit.cache`
- `.phpunit.result.cache`
- `composer.lock`
- `coverage`
- `node_modules`
- `tests`
- `var`
- SDK package tests
- `vendor/doctrine`
- `vendor/symfony`

## Manual Reproduction

Do not run zip-install smoke against the local synced Shopware lanes while the
two-way plugin Mutagen sync is active. The zip install replaces
`custom/plugins/SwagAgenticCommerce`, and the existing plugin sync can copy the
unzipped artifact back into this source checkout.

For local packaging-only validation, use a throwaway container or CI-like
workspace. Full zip-install validation should run through GitHub Actions.

## Maintenance Rules

- Keep one zip artifact for all supported lanes.
- Keep normal source installs marker-free.
- Keep `executeComposerCommands()` enabled unless the bundled SDK marker exists.
- Keep SDK files in plugin-local `vendor/` only for packaged tester zips.
- Keep `.tools/vendor` for repo-local tooling only.
- Keep package assets Vite-built.
- Do not copy generated lane state into the artifact.
