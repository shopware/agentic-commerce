#!/usr/bin/env bash
# Assert that a packaged extension zip actually carries the UCP SDK it autoloads.
#
# The failure this exists for is silent in every other check. `composer` records a path
# repository's package in vendor/composer/installed.json and writes an autoload entry
# pointing at $vendorDir/ucp-php-sdk/<pkg>/src -- and if the package directory was never
# copied in, nothing complains. `shopware-cli extension validate` does not read vendor/,
# and bin/ci-assert-zip-admin-bundle.sh only reads the administration bundle. So the
# archive installs, the plugin activates, and the first UCP request dies with
# `Class "Ucp\Sdk\..." not found`.
#
# That is not hypothetical: shopware-cli copies the extension into a temp directory before
# running composer, so a path repository aimed at a sibling checkout (`../ucp-php-sdk`)
# resolves during planning and materialises nothing.
#
# Guards three things:
#   - the two SDK packages have real PHP files under vendor/
#   - the autoloader points at those same paths
#   - the archive does not also ship the build-only .sdk/ source copy

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-assert-zip-vendors-sdk.sh <extension-zip>" >&2
  exit 1
fi

zip_file="$1"

for dependency in unzip; do
  if ! command -v "${dependency}" >/dev/null 2>&1; then
    echo "Missing required dependency: ${dependency}" >&2
    exit 1
  fi
done

if [[ ! -f "${zip_file}" ]]; then
  echo "No such zip: ${zip_file}" >&2
  exit 1
fi

readonly PLUGIN="SwagAgenticCommerce"
# A trivially small count would mean a stub or a partial copy. core alone is well over 100
# files, so this floor only catches "essentially nothing got copied".
readonly MIN_PHP_FILES=50

listing="$(unzip -Z1 "${zip_file}")"

status=0

for package in core symfony-bundle; do
  count="$(printf '%s\n' "${listing}" \
    | grep -c "^${PLUGIN}/vendor/ucp-php-sdk/${package}/.*\.php$" || true)"

  if [[ "${count}" -eq 0 ]]; then
    echo "FAIL: vendor/ucp-php-sdk/${package} ships no PHP files." >&2
    echo "      The autoloader will point at a directory that is not in the archive." >&2
    status=1
  elif [[ "${count}" -lt "${MIN_PHP_FILES}" ]]; then
    echo "FAIL: vendor/ucp-php-sdk/${package} ships only ${count} PHP files." >&2
    echo "      That is a partial copy, not the package." >&2
    status=1
  else
    echo "ok: vendor/ucp-php-sdk/${package} ships ${count} PHP files."
  fi
done

# The autoloader has to agree with what is on disk. If composer recorded the package under
# a different install path than the one packed, the file count above can pass while nothing
# is reachable.
autoload_psr4="$(unzip -p "${zip_file}" "${PLUGIN}/vendor/composer/autoload_psr4.php" 2>/dev/null || true)"

if [[ -z "${autoload_psr4}" ]]; then
  echo "FAIL: ${PLUGIN}/vendor/composer/autoload_psr4.php is missing from the archive." >&2
  echo "      Without it Shopware cannot autoload the SDK at all." >&2
  status=1
else
  for expected in 'ucp-php-sdk/core/src' 'ucp-php-sdk/symfony-bundle/src'; do
    if ! printf '%s\n' "${autoload_psr4}" | grep -Fq "${expected}"; then
      echo "FAIL: autoload_psr4.php does not map anything to ${expected}." >&2
      status=1
    fi
  done
fi

# .sdk/ is the build-only source the path repositories resolve from. Shipping it too would
# put a second copy of the SDK in the archive, which is how a plugin ends up with two
# versions of the same class on disk.
if printf '%s\n' "${listing}" | grep -q "^${PLUGIN}/\.sdk/"; then
  echo "FAIL: the archive ships the build-only .sdk/ source copy." >&2
  echo "      Add .sdk to zip.pack.excludes.paths in .shopware-extension.yml." >&2
  status=1
fi

if [[ "${status}" -ne 0 ]]; then
  exit 1
fi

echo "ok: the archive carries the SDK its autoloader points at."
