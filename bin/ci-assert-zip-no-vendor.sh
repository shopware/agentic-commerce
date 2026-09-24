#!/usr/bin/env bash
# Assert that a packaged extension zip carries no dependencies of its own.
#
# The plugin returns true from executeComposerCommands(), so Shopware runs
# `composer require shopware/agentic-commerce:<version>` in the shop when the archive is
# installed. `shopware/production` declares `custom/plugins/*` as a path repository, so that
# resolves the extracted archive locally and pulls the pinned UCP SDK from Packagist into the
# project -- which is why the archive must ship none of it itself.
#
# 1.3.0 shipped the other way round: the SDK vendored into the plugin's own vendor/ with the
# autoloader Composer generates for it, which the plugin then required. That registers a second
# Composer ClassLoader in every shop, reported by FroshTools as "2 autoloaders registered"
# (FriendsOfShopware/FroshTools#469) and able to answer version lookups the shop's own registry
# should own.
#
# Guards four things:
#   - no vendor/ tree and no marker file: nothing is bundled
#   - the two SDK packages are still required, so the install has something to resolve
#   - composer.json carries a concrete version -- the path repository derives the package version
#     from it, and a require of `<name>:<version>` that cannot match fails the install
#   - no build-only trees (.sdk, .tools, node_modules)

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-assert-zip-no-vendor.sh <extension-zip>" >&2
  exit 1
fi

zip_file="$1"

if ! command -v unzip >/dev/null 2>&1; then
  echo "Missing required dependency: unzip" >&2
  exit 1
fi

if [[ ! -f "${zip_file}" ]]; then
  echo "No such zip: ${zip_file}" >&2
  exit 1
fi

readonly PLUGIN="SwagAgenticCommerce"

# Consumers must read the whole listing. grep -q can close early on a large archive,
# making printf fail with SIGPIPE under pipefail even when the expected file exists.
listing="$(unzip -Z1 "${zip_file}")"

status=0

bundled="$(printf '%s\n' "${listing}" | grep -E "^${PLUGIN}/vendor/" || true)"

if [[ -n "${bundled}" ]]; then
  echo "FAIL: the archive ships a vendor/ tree ($(printf '%s\n' "${bundled}" | wc -l | tr -d ' ') entries)." >&2
  echo "      Shopware resolves this plugin's requirements through Composer at install time;" >&2
  echo "      a bundled copy registers a second autoloader in the shop." >&2
  status=1
else
  echo "ok: the archive bundles no dependencies."
fi

for build_only in .sdk .tools node_modules; do
  if printf '%s\n' "${listing}" | grep "^${PLUGIN}/${build_only}/" >/dev/null; then
    echo "FAIL: the archive ships the build-only ${build_only}/ tree." >&2
    echo "      Add ${build_only} to zip.pack.excludes.paths in .shopware-extension.yml." >&2
    status=1
  fi
done

if printf '%s\n' "${listing}" | grep -Fx "${PLUGIN}/.swag-agentic-commerce-bundled-sdk" >/dev/null; then
  echo "FAIL: the archive still carries the bundled-SDK marker file." >&2
  status=1
fi

if ! python3 - "${zip_file}" <<'PYTHON'
import json
import sys
import zipfile

PLUGIN = 'SwagAgenticCommerce'
failures = []

with zipfile.ZipFile(sys.argv[1]) as archive:
    manifest = json.loads(archive.read(f'{PLUGIN}/composer.json'))

# A path repository takes the package version from this field, and Shopware requires
# `<name>:<version>`. Without it the install fails on a constraint nothing can satisfy.
version = manifest.get('version')
if not isinstance(version, str) or version == '':
    failures.append(f'composer.json declares no version (got {version!r})')

for package in ('ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'):
    constraint = manifest.get('require', {}).get(package)
    if not constraint:
        failures.append(f'composer.json no longer requires {package}, so nothing installs it')

psr4 = manifest.get('autoload', {}).get('psr-4', {})
if psr4.get('Swag\\AgenticCommerce\\') != 'src/':
    failures.append(f"autoload.psr-4 must map Swag\\AgenticCommerce\\ to src/, got {psr4!r}")

for failure in failures:
    print(f'FAIL: {failure}', file=sys.stderr)

if failures:
    sys.exit(1)

print(f'ok: version {version}, SDK required, own namespace autoloaded.')
PYTHON
then
  status=1
fi

if [[ "${status}" -ne 0 ]]; then
  exit 1
fi

echo "ok: the archive leaves its dependencies to Composer."
