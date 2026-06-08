# Main-Merge Release-Candidate ZIP

This document describes how `SwagAgenticCommerce` produces one self-contained
tester ZIP for Shopware admin upload.

The archive is for tester distribution while the SDK packages are private or
not reliably installable by testers. It is not the final store-ready release
strategy.

## Output

Successful `main` and manual workflow runs produce raw GitHub Actions artifacts:

```text
release-candidate-untested.zip
release-candidate-untested.zip.sha256
release-candidate-untested-metadata.json
release-candidate-final.zip
release-candidate-final.zip.sha256
release-candidate-final-metadata.json
```

The `*.zip` artifacts are uploaded with `actions/upload-artifact@v7` and
`archive: false`. GitHub therefore serves the plugin ZIP itself, not a wrapper
ZIP containing the plugin ZIP.

Use `release-candidate-final.zip` for tester installation. It can be uploaded
directly in the Shopware administration extension upload flow.

There must not be separate ZIPs for `6.5.x`, `6.6.x`, and `trunk`. If one lane
fails, fix shared compatibility or packaging layout.

## Workflow

The workflow lives in `.github/workflows/ci.yml`.

Pull requests run the full validation matrix:

- `php-quality`
- `admin-static`
- `shopware-matrix`
- `admin-matrix`
- `storefront-matrix`

Pull requests do not build or publish release-candidate artifacts.

`push` to `main` and `workflow_dispatch` build and zip-smoke the release
candidate:

| Job | Purpose |
| --- | --- |
| `verify-main-validation` | Decides whether the full validation matrix must run on `main`. |
| `package-test-zip` | Builds `release-candidate-untested.zip` immediately. |
| `zip-install-smoke` | Installs the untested ZIP on `6.5.x`, `6.6.x`, and `trunk`. |
| `publish-test-zip` | Promotes the exact same ZIP bytes to `release-candidate-final.zip`. |

The main-run critical path is:

```text
package-test-zip -> zip-install-smoke -> publish-test-zip
```

If `verify-main-validation` cannot prove that the merged PR checks were green,
the full validation matrix also runs on `main`, and `publish-test-zip` waits for
both zip smoke and that fallback matrix.

## Validation Decision

`verify-main-validation` handles event-specific behavior:

| Event | Decision |
| --- | --- |
| `pull_request` | Run the full validation matrix. |
| `workflow_dispatch` | Run only RC packaging and zip smoke unless `run_full_matrix` is enabled. |
| `push` to `main` | Trust the associated merged PR only when all expected checks are successful. |

For `push` to `main`, the job looks up the merged PR associated with
`github.sha` and checks the expected PR checks:

```text
admin-static
php-quality
shopware-matrix (6.5.x)
shopware-matrix (6.6.x)
shopware-matrix (trunk)
admin-matrix (6.5.x, webpack)
admin-matrix (6.6.x, webpack)
admin-matrix (6.6.x, vite)
admin-matrix (trunk, vite)
storefront-matrix (6.5.x)
storefront-matrix (6.6.x)
storefront-matrix (trunk)
```

It sets `run_full_matrix=false` only when all expected checks are present and
successful. It sets `run_full_matrix=true` when the PR is missing, checks are
missing, checks are not successful, or GitHub cannot be queried reliably.

This treats direct pushes, bypassed merges, and unverifiable states as unsafe.
They are slower, but safe: the full matrix must pass on `main` before the final
release-candidate artifact is published.

The final metadata records the validation decision under `main_validation`.

## Package Job

`package-test-zip` starts immediately for non-PR runs. It does not wait for the
normal validation matrix.

The job:

1. Checks out the plugin into `agentic-commerce`.
2. Checks out `shopware/shopware` trunk/current 6.7 into `shopware`.
3. Checks out `shopware/ucp-php-sdk` into `ucp-php-sdk`.
4. Installs PHP and Shopware CLI.
5. Writes the CI Compose file for trunk.
6. Runs trunk Vite admin smoke with `CI_ADMIN_EXPORT_PLUGIN_PUBLIC`.
7. Runs `bin/ci-package-test-zip.sh`.
8. Copies the generated package to `release-candidate-untested.zip`.
9. Uploads the raw untested ZIP, checksum, and metadata with one-day retention.

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

with `symlink: false`, so the ZIP contains real package files:

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

The tester ZIP has:

```text
.swag-agentic-commerce-bundled-sdk
```

For that artifact:

```php
executeComposerCommands() === false
```

This prevents Shopware from trying to run root Composer commands during ZIP
installation. Testers do not need SDK path repositories or SDK Composer
credentials.

`getAdditionalBundles()` loads plugin-local `vendor/autoload.php` when the
marker exists before checking for `Ucp\Sdk\Symfony\UcpSdkBundle`. Route loading
uses the bundled SDK path when the marker exists and Composer metadata
otherwise.

## Shopware CLI

The package archive is created with:

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
The package script copies it into `src/Resources/public` because ZIP installs
run `assets:install` against that directory and do not rebuild administration
assets.

Required source validation remains:

| Lane | Required admin validation |
| --- | --- |
| `6.5.x` | webpack |
| `6.6.x` | webpack and Vite |
| `trunk` / current `6.7` | Vite |

If the packaged Vite asset set fails on `6.5.x` or `6.6.x`, fix the bootstrap
or package layout. Do not split the artifact into lane-specific ZIPs.

## Zip-Install Smoke

`zip-install-smoke` downloads `release-candidate-untested.zip` and runs once per
lane:

- `6.5.x`
- `6.6.x`
- `trunk`

Each run:

1. Checks out the plugin for test scripts and Playwright tests.
2. Checks out the matching Shopware lane.
3. Downloads the raw untested ZIP.
4. Builds the core Shopware administration shell with
   `CI_ADMIN_CORE_ONLY=1`.
5. Runs `bin/ci-smoke.sh` with `CI_SMOKE_PLUGIN_ZIP`.
6. Runs the admin Playwright suite.
7. Tears down the stack.

The core administration shell build intentionally runs before the ZIP is
installed. It keeps `custom/plugins/SwagAgenticCommerce` absent while compiling
the Shopware shell:

- `6.5.x`: webpack shell build
- `6.6.x`: webpack shell build
- `trunk` / current `6.7`: Vite shell build

This gives the browser tests a real `/admin` shell without rebuilding
`SwagAgenticCommerce` assets per lane. The plugin assets used by the browser are
still the single packaged asset set from the ZIP.

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

## Final Promotion

`publish-test-zip` runs only after zip-install smoke passes. If
`verify-main-validation` requested fallback validation, it also waits for the
full validation matrix to pass on `main`.

The job downloads:

```text
release-candidate-untested.zip
release-candidate-untested.zip.sha256
release-candidate-untested-metadata.json
```

It verifies the untested checksum, copies the same ZIP bytes to
`release-candidate-final.zip`, writes a final checksum, enriches the metadata
with `main_validation`, and uploads:

```text
release-candidate-final.zip
release-candidate-final.zip.sha256
release-candidate-final-metadata.json
```

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

Do not run ZIP-install smoke against the local synced Shopware lanes while the
two-way plugin Mutagen sync is active. The ZIP install replaces
`custom/plugins/SwagAgenticCommerce`, and the existing plugin sync can copy the
unzipped artifact back into this source checkout.

For local packaging-only validation, use a throwaway container or CI-like
workspace. Full ZIP-install validation should run through GitHub Actions.

## Maintenance Rules

- Keep one ZIP artifact for all supported lanes.
- Keep normal source installs marker-free.
- Keep `executeComposerCommands()` enabled unless the bundled SDK marker exists.
- Keep SDK files in plugin-local `vendor/` only for packaged tester ZIPs.
- Keep `.tools/vendor` for repo-local tooling only.
- Keep package assets Vite-built.
- Keep raw RC artifacts as single files uploaded with `archive: false`.
