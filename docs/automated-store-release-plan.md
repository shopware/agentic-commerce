# Automated Shopware Store Release

## Summary

Replace the custom release-candidate ZIP pipeline with Shopware's standard
[`store-release` action](https://github.com/shopware/github-actions/tree/main/store-release).
Releases will be manually dispatched from `main`, require green CI for the exact
commit, upload `SwagAgenticCommerce` to Store product `21761`, and create the
GitHub tag and release.

## Implementation Changes

- Add `store-release.yml` with:
  - `workflow_dispatch`, single-release concurrency, and `contents: write`.
  - Guards requiring the selected ref to equal the current `main` HEAD and its
    CI workflow to have completed successfully.
  - Preflight validation for a new semantic Composer version and matching
    English and German changelog entries.
  - `shopware/github-actions/store-release@main` using hardcoded
    `extensionName: SwagAgenticCommerce`, `GITHUB_TOKEN`,
    `SHOPWARE_CLI_ACCOUNT_CLIENT_ID`, and
    `SHOPWARE_CLI_ACCOUNT_CLIENT_SECRET`.
  - Default action behavior retained: Store upload, unprefixed version tag,
    GitHub release, and release ZIP. Store-page metadata is not overwritten.

- Make the repository compatible with standard packaging:
  - Add Composer `version: 1.1.0` as the next backward-compatible feature release;
    every later release PR must bump it.
  - Add `CHANGELOG.md` and `CHANGELOG_de-DE.md`, initially documenting 1.1.0;
    each release must add the new version to both.
  - Enable Shopware CLI administration asset building against the 6.7/Vite
    line and add an asset hook that installs the existing legacy bootstrap
    beside the Vite entrypoints, preserving 6.5/6.6 compatibility.
  - Rely on the public Packagist SDK packages instead of embedding a private
    vendor tree.
  - Remove bundled-SDK marker handling, custom autoloading, and marker-specific
    route resolution; normal Shopware Composer installation becomes the only
    runtime path.

- Simplify CI and obsolete packaging support:
  - Delete `package-test-zip`, `zip-install-smoke`, and `publish-test-zip`, plus
    the packaging script and release-candidate documentation.
  - Remove the ZIP-install mode from the smoke orchestrator while retaining
    normal source-based lane smoke tests.
  - Add a stable `validation-gate` job covering shell lint, PHP quality,
    Shopware lanes, functional tests, administration builds/browser checks,
    and storefront builds/browser checks.
  - Keep the existing optimization that trusts complete PR checks for a merge
    commit; direct or unverifiable pushes rerun the full matrix.
  - Update README release instructions and remove statements that the SDK is
    unavailable through Packagist.

## Validation

- Run the normal full CI matrix and require `validation-gate` to pass.
- Build locally with the same `shopware-cli extension zip --release` path and
  run `shopware-cli extension validate`.
- Inspect the package for Composer metadata, Vite entrypoints, the legacy
  administration loader, and absence of development files or bundled-SDK
  markers/vendor workarounds.
- Confirm release preflight rejects a feature branch, stale main commit,
  missing or failed CI, an existing `v<version>` or `<version>` tag, or a
  missing changelog entry.
- The first live dispatch after a version bump must create the Store version,
  GitHub tag, GitHub release, and release ZIP.

## Assumptions

- Accept the exact main commit's already-green CI instead of rerunning the full
  matrix during release.
- Repository administrators will add the currently missing
  `SHOPWARE_CLI_ACCOUNT_CLIENT_ID` and
  `SHOPWARE_CLI_ACCOUNT_CLIENT_SECRET` secrets before the first dispatch.
- The Store listing remains Beta. Publishing is explicit; the default workflow
  dispatch only builds and validates the package.
