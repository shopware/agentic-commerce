#!/usr/bin/env bash
# Assert that a packaged extension zip carries the UCP SDK as plain source and nothing Composer owns.
#
# The plugin returns false from executeComposerCommands(), so Shopware never runs Composer for it.
# bin/ci-bundle-sdk-into-archive.sh therefore puts the SDK under lib/ucp-php-sdk/ and rewrites the
# archive's composer.json so that Shopware adds the SDK's PSR-4 prefixes to the shop's own class loader.
#
# 1.3.0 shipped the SDK in the plugin's own vendor/ with the autoloader Composer generates for it. That
# registers a second Composer ClassLoader in every shop, reported by FroshTools as "2 autoloaders
# registered" (FriendsOfShopware/FroshTools#469) and able to answer version lookups the shop's own
# registry should own. A vendor/composer in the archive would bring that back.
#
# Guards:
#   - no vendor/ tree and no vendor/composer anywhere
#   - composer.json carries a concrete version, requires no SDK package, replaces both at a literal
#     version, and maps each SDK namespace to its lib/ directory as a plain string (shopware-cli fails on
#     a list)
#   - lib/preload-classes.php is present
#   - no build-only trees (.sdk, .tools, node_modules, vendor-bin)
#   - no test scaffolding (Jest specs, src/Ucp/Test), which .shopware-extension.yml removes
#
# Usage: bin/ci-assert-zip-bundles-sdk.sh <extension-zip> [<bundled-plugin-dir>]
#
# <bundled-plugin-dir> is the checkout bin/ci-bundle-sdk-into-archive.sh ran on. With it, the archive must
# hold exactly as many schemas as the bundled package; without it the count only has to be above zero.

set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: bin/ci-assert-zip-bundles-sdk.sh <extension-zip> [<bundled-plugin-dir>]" >&2
  exit 1
fi

zip_file="$1"
bundled_plugin_dir="${2:-}"

for tool in unzip jq; do
  if ! command -v "${tool}" >/dev/null 2>&1; then
    echo "Missing required dependency: ${tool}" >&2
    exit 1
  fi
done

if [[ ! -f "${zip_file}" ]]; then
  echo "No such zip: ${zip_file}" >&2
  exit 1
fi

readonly PLUGIN="SwagAgenticCommerce"
readonly SDK_PACKAGES=(ucp-php-sdk/core ucp-php-sdk/symfony-bundle)

# Consumers must read the whole listing. grep -q can close early on a large archive,
# making printf fail with SIGPIPE under pipefail even when the expected file exists.
listing="$(unzip -Z1 "${zip_file}")"

status=0

fail() {
  echo "FAIL: $1" >&2
  status=1
}

vendor_entries="$(printf '%s\n' "${listing}" | grep -E "^${PLUGIN}/(.*/)?vendor/" || true)"
if [[ -n "${vendor_entries}" ]]; then
  fail "the archive ships a vendor/ tree ($(printf '%s\n' "${vendor_entries}" | wc -l | tr -d ' ') entries)."
  echo "      The SDK belongs under lib/ucp-php-sdk/; a vendor/ means Composer ran against the archive." >&2
fi
if printf '%s\n' "${listing}" | grep -E "(^|/)vendor/composer(/|$)" >/dev/null; then
  fail "the archive ships vendor/composer, which would register a second autoloader in the shop."
fi
if [[ -z "${vendor_entries}" ]]; then
  echo "ok: the archive has no vendor/ tree."
fi

for package in "${SDK_PACKAGES[@]}"; do
  package_dir="${PLUGIN}/lib/${package}"
  for required in src/ composer.json; do
    if ! printf '%s\n' "${listing}" | grep -F "${package_dir}/${required}" >/dev/null; then
      fail "the archive does not bundle ${package_dir}/${required}."
    fi
  done
done

schema_count="$(printf '%s\n' "${listing}" | grep -cE "^${PLUGIN}/lib/ucp-php-sdk/core/resources/.*\.json$" || true)"
if [[ "${schema_count}" -eq 0 ]]; then
  fail "the archive bundles no JSON schemas under lib/ucp-php-sdk/core/resources/."
elif [[ -n "${bundled_plugin_dir}" ]]; then
  expected_schema_count="$(find "${bundled_plugin_dir}/lib/ucp-php-sdk/core/resources" -type f -name '*.json' | wc -l | tr -d ' ')"
  if [[ "${schema_count}" -ne "${expected_schema_count}" ]]; then
    fail "the archive bundles ${schema_count} JSON schemas, the bundled package has ${expected_schema_count}."
  else
    echo "ok: the archive bundles both SDK packages and all ${schema_count} schemas."
  fi
else
  echo "ok: the archive bundles both SDK packages and ${schema_count} schemas."
fi

if ! printf '%s\n' "${listing}" | grep -Fx "${PLUGIN}/lib/preload-classes.php" >/dev/null; then
  fail "the archive has no lib/preload-classes.php."
fi

for build_only in .sdk .tools node_modules vendor-bin; do
  if printf '%s\n' "${listing}" | grep "^${PLUGIN}/${build_only}/" >/dev/null; then
    fail "the archive ships the build-only ${build_only}/ tree."
    echo "      Add ${build_only} to zip.pack.excludes.paths in .shopware-extension.yml." >&2
  fi
done

# The Jest specs are deleted by a pack.before_hooks step, not an excludes path, so a shopware-cli
# change to hooks would ship them silently. Assert the result rather than the configuration.
test_scaffolding_paths="$(printf '%s\n' "${listing}" | grep -E "^${PLUGIN}/(.*\.spec\.(js|ts)$|src/Ucp/Test/|tests/)" || true)"

if [[ -n "${test_scaffolding_paths}" ]]; then
  fail "the archive ships test scaffolding ($(printf '%s\n' "${test_scaffolding_paths}" | wc -l | tr -d ' ') entries)."
  printf '%s\n' "${test_scaffolding_paths}" | sed 's/^/      /' >&2
  echo "      Check zip.pack in .shopware-extension.yml (excludes.paths and before_hooks)." >&2
else
  echo "ok: the archive ships no test scaffolding."
fi

if printf '%s\n' "${listing}" | grep -Fx "${PLUGIN}/.swag-agentic-commerce-bundled-sdk" >/dev/null; then
  fail "the archive still carries the bundled-SDK marker file."
fi

# --- the manifest ---------------------------------------------------------------------------------

if ! manifest="$(unzip -p "${zip_file}" "${PLUGIN}/composer.json")"; then
  fail "the archive has no ${PLUGIN}/composer.json."
  exit 1
fi

# Shopware reads the plugin version from here, and a path repository derives the package version from it.
version="$(jq -r '.version // empty' <<< "${manifest}")"
if [[ -z "${version}" ]]; then
  fail "composer.json declares no version."
fi

sdk_requirements="$(jq -r '(.require // {}) | keys[] | select(startswith("ucp-php-sdk/"))' <<< "${manifest}")"
if [[ -n "${sdk_requirements}" ]]; then
  fail "composer.json still requires $(tr '\n' ' ' <<< "${sdk_requirements}")so Shopware would resolve it through Composer."
fi

if [[ "$(jq -r '.autoload["psr-4"]["Swag\\AgenticCommerce\\"] // empty' <<< "${manifest}")" != "src/" ]]; then
  fail "autoload.psr-4 must map Swag\\AgenticCommerce\\ to src/."
fi

for package in "${SDK_PACKAGES[@]}"; do
  replaced_version="$(jq -r --arg package "${package}" '.replace[$package] // empty' <<< "${manifest}")"
  if [[ ! "${replaced_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]]; then
    fail "composer.json must replace ${package} at a literal version, got '${replaced_version}'."
  fi

  # The namespace comes from the bundled package itself, so the check does not hard-code it.
  if ! bundled_manifest="$(unzip -p "${zip_file}" "${PLUGIN}/lib/${package}/composer.json" 2>/dev/null)"; then
    continue
  fi
  if [[ "$(jq -r '.name' <<< "${bundled_manifest}")" != "${package}" ]]; then
    fail "lib/${package}/composer.json names another package."
  fi
  namespace="$(jq -r '.autoload["psr-4"] | keys[0] // empty' <<< "${bundled_manifest}")"
  mapped_path="$(jq -r --arg namespace "${namespace}" '.autoload["psr-4"][$namespace] | if type == "string" then . else "not a string" end' <<< "${manifest}")"
  if [[ -z "${namespace}" || "${mapped_path}" != "lib/${package}/src/" ]]; then
    fail "autoload.psr-4 must map ${namespace:-the namespace of ${package}} to the string lib/${package}/src/, got '${mapped_path}'."
  fi
done

if [[ "${status}" -ne 0 ]]; then
  exit 1
fi

echo "ok: version ${version}, both SDK packages replaced at a literal version, own and SDK namespaces autoloaded."
echo "ok: the archive bundles the SDK and leaves Composer out of the install."
