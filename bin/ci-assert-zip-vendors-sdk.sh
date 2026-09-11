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

if ! command -v unzip >/dev/null 2>&1; then
  echo "Missing required dependency: unzip" >&2
  exit 1
fi

if [[ ! -f "${zip_file}" ]]; then
  echo "No such zip: ${zip_file}" >&2
  exit 1
fi

readonly PLUGIN="SwagAgenticCommerce"
# Deliberately a low floor. An earlier revision set this to 50 and failed a correct
# archive, because ucp-php-sdk/symfony-bundle genuinely has 49 source files -- a count
# threshold has to be re-tuned every time the package legitimately changes shape, and
# tuning it against the current tree is how it ends up asserting nothing. The real
# invariant is the named classes below; this only catches "essentially nothing arrived".
readonly MIN_PHP_FILES=10

# Load-bearing classes. If the copy is partial in a way a count would miss, one of these
# is what actually breaks: the bundle Shopware registers, the extension that reads the
# configuration, and a core class the plugin constructs directly.
readonly REQUIRED_CLASSES=(
  "vendor/ucp-php-sdk/symfony-bundle/src/UcpSdkBundle.php"
  "vendor/ucp-php-sdk/symfony-bundle/src/DependencyInjection/UcpSdkExtension.php"
  "vendor/ucp-php-sdk/symfony-bundle/src/Bridge/DoctrineDbal/SchemaBootstrapper.php"
  "vendor/ucp-php-sdk/core/src/Model/Profile/PlatformProfile.php"
  "vendor/ucp-php-sdk/core/src/Enum/UcpProtocolVersion.php"
)

# Consumers must read the whole listing. grep -q can close early on a large archive,
# making printf fail with SIGPIPE under pipefail even when the expected file exists.
listing="$(unzip -Z1 "${zip_file}")"

status=0

# A full Composer resolve also installs Shopware and Symfony. Those belong to the
# host shop; carrying them here overrides its versions and raises the PHP minimum.
python3 - "${zip_file}" <<'PYTHON'
import json
import sys
import zipfile

with zipfile.ZipFile(sys.argv[1]) as archive:
    metadata = json.loads(archive.read('SwagAgenticCommerce/vendor/composer/installed.json'))
names = {package['name'] for package in metadata['packages']}
expected = {'ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'}
if names != expected:
    sys.exit(f'FAIL: bundled runtime must contain only the two SDK packages; found {sorted(names)}')
print('ok: bundled runtime contains only the two SDK packages.')
PYTHON

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

for class_path in "${REQUIRED_CLASSES[@]}"; do
  if ! printf '%s\n' "${listing}" | grep -Fx "${PLUGIN}/${class_path}" >/dev/null; then
    echo "FAIL: ${class_path} is not in the archive." >&2
    status=1
  fi
done

# The SDK ships the generated JSON Schemas it validates every request and response
# against, and they are resources rather than PHP -- so a copy that took only *.php would
# pass every check above and then refuse traffic at runtime.
schema_count="$(printf '%s\n' "${listing}" \
  | grep -c "^${PLUGIN}/vendor/ucp-php-sdk/core/resources/schema/.*\.json$" || true)"

if [[ "${schema_count}" -lt 30 ]]; then
  echo "FAIL: only ${schema_count} generated schema files shipped." >&2
  echo "      The SDK validates every request and response against these." >&2
  status=1
else
  echo "ok: ${schema_count} schema files ship."
fi

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
    if ! printf '%s\n' "${autoload_psr4}" | grep -F "${expected}" >/dev/null; then
      echo "FAIL: autoload_psr4.php does not map anything to ${expected}." >&2
      status=1
    fi
  done
fi

# .sdk/ is the build-only source the path repositories resolve from. Shipping it too would
# put a second copy of the SDK in the archive, which is how a plugin ends up with two
# versions of the same class on disk.
if printf '%s\n' "${listing}" | grep "^${PLUGIN}/\.sdk/" >/dev/null; then
  echo "FAIL: the archive ships the build-only .sdk/ source copy." >&2
  echo "      Add .sdk to zip.pack.excludes.paths in .shopware-extension.yml." >&2
  status=1
fi

if [[ "${status}" -ne 0 ]]; then
  exit 1
fi

echo "ok: the archive carries the SDK its autoloader points at."
