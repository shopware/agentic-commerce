#!/usr/bin/env bash
# Assert that a packaged extension zip actually carries the UCP SDK it autoloads.
#
# The failure this exists for is silent in every other check. `composer` records a path
# repository's package in vendor/composer/installed.json -- and if the package directory was
# never copied in, nothing complains. `shopware-cli extension validate` does not read vendor/,
# and bin/ci-assert-zip-admin-bundle.sh only reads the administration bundle. So the
# archive installs, the plugin activates, and the first UCP request dies with
# `Class "Ucp\Sdk\..." not found`.
#
# That is not hypothetical: shopware-cli copies the extension into a temp directory before
# running composer, so a path repository aimed at a sibling checkout (`../ucp-php-sdk`)
# resolves during planning and materialises nothing.
#
# Guards four things:
#   - the two SDK packages have real PHP files and JSON schemas under vendor/
#   - the plugin manifest declares the psr-4 prefixes for them itself, with config.vendor-dir
#     set so Shopware's requirement validator still finds the bundled packages
#   - the archive registers no Composer autoloader of its own
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

# Shopware autoloads a plugin's own declared psr-4 prefixes, and the build points them at the
# bundled packages. If that injection does not happen, nothing else notices: the SDK is on disk,
# the manifest looks ordinary, and the first request dies with `Class "Ucp\Sdk\..." not found`.
if ! python3 - "${zip_file}" <<'PYTHON'
import json
import posixpath
import sys
import zipfile

PLUGIN = 'SwagAgenticCommerce'
failures = []

with zipfile.ZipFile(sys.argv[1]) as archive:
    names = set(archive.namelist())
    manifest = json.loads(archive.read(f'{PLUGIN}/composer.json'))
    installed = json.loads(archive.read(f'{PLUGIN}/vendor/composer/installed.json'))
    packages = {
        package['name']: json.loads(archive.read(f"{PLUGIN}/vendor/{package['name']}/composer.json"))
        for package in installed['packages']
    }

psr4 = manifest.get('autoload', {}).get('psr-4', {})


def declared(namespace):
    paths = psr4.get(namespace, [])

    return [paths] if isinstance(paths, str) else list(paths)


# vendor-dir is what points RequirementsValidator::validateShippedDependencies() at the
# installed.json read above. Left at the source checkout's .tools/vendor, the validator finds no
# shipped dependencies and the install fails on a requirement the archive actually carries.
vendor_dir = manifest.get('config', {}).get('vendor-dir')
if vendor_dir != 'vendor':
    failures.append(f'composer.json config.vendor-dir must be "vendor", got {vendor_dir!r}')

# Every bundled package's own prefixes must be declared by the plugin, pointing into that
# package. Derived from the packages rather than hardcoded, so this keeps holding when the SDK
# renames a namespace.
for name, package in sorted(packages.items()):
    if name not in manifest.get('require', {}):
        failures.append(f'{name} is bundled but no longer required in composer.json')

    for namespace, paths in package['autoload']['psr-4'].items():
        for path in [paths] if isinstance(paths, str) else paths:
            expected = posixpath.join('vendor', name, path.strip('/')) + '/'

            if expected not in declared(namespace):
                failures.append(
                    f'composer.json autoload.psr-4[{namespace!r}] does not map {expected!r}'
                    f' (declares {declared(namespace)!r})'
                )
            elif not any(entry.startswith(f'{PLUGIN}/{expected}') for entry in names):
                failures.append(f'{expected} is declared in autoload.psr-4 but not in the archive')

# Independent of the derivation above: if the SDK ever ships something unrecognisable, the loop
# can agree with itself while the classes the plugin names are unreachable.
for namespace in ('Ucp\\Sdk\\', 'Ucp\\Sdk\\Symfony\\'):
    if not declared(namespace):
        failures.append(f'composer.json autoload.psr-4 declares nothing for {namespace!r}')

for failure in failures:
    print(f'FAIL: {failure}', file=sys.stderr)

if failures:
    sys.exit(1)

print(f'ok: the plugin manifest autoloads the bundled SDK itself ({len(psr4)} prefixes).')
PYTHON
then
  status=1
fi

# A plugin-local vendor/autoload.php is a second Composer ClassLoader, and its
# vendor/composer/installed.php then joins the shop's own in
# InstalledVersions::getAllRawData() -- where FroshTools reports "2 autoloaders registered" and
# Composer's aggregate version lookups can answer from the plugin's copy.
# https://github.com/FriendsOfShopware/FroshTools/issues/469
composer_runtime="$(printf '%s\n' "${listing}" \
  | grep -E "^${PLUGIN}/vendor/(autoload\.php|composer/.+)$" || true)"

if [[ "${composer_runtime}" != "${PLUGIN}/vendor/composer/installed.json" ]]; then
  echo "FAIL: the archive ships a Composer autoloader runtime under vendor/:" >&2
  printf '%s\n' "${composer_runtime}" | sed 's/^/        /' >&2
  echo "      Only ${PLUGIN}/vendor/composer/installed.json may ship; the rest registers an" >&2
  echo "      autoloader the shop does not need and must not see." >&2
  status=1
else
  echo "ok: the archive registers no Composer autoloader of its own."
fi

# Build-only trees. .sdk/ is the source the path repositories resolve from -- shipping it too
# would put a second copy of the SDK in the archive, which is how a plugin ends up with two
# versions of the same class on disk. .tools/ and node_modules/ are development tooling, and
# `extension zip --disable-git` copies the working tree verbatim, so a local build in a
# developer's checkout ships them unless they are excluded.
for build_only in .sdk .tools node_modules; do
  if printf '%s\n' "${listing}" | grep "^${PLUGIN}/${build_only}/" >/dev/null; then
    echo "FAIL: the archive ships the build-only ${build_only}/ tree." >&2
    echo "      Add ${build_only} to zip.pack.excludes.paths in .shopware-extension.yml." >&2
    status=1
  fi
done

if [[ "${status}" -ne 0 ]]; then
  exit 1
fi

echo "ok: the archive carries the SDK its autoloader points at."
