#!/usr/bin/env bash
# Bundles the UCP SDK into a plugin checkout that is about to be packed into the release archive.
#
# The archive ships the SDK as plain source under lib/ucp-php-sdk/ and registers it through its own
# autoload.psr-4 entries, which Shopware adds to the shop's single Composer class loader. The plugin
# returns false from executeComposerCommands(), so Shopware never runs Composer for it.
#
# This rewrites <plugin-dir>/composer.json for that archive, so run it on a throwaway checkout, never
# on a working copy you develop in. The repository's own composer.json keeps installing the SDK
# through Composer.
#
# Usage: bin/ci-bundle-sdk-into-archive.sh <plugin-dir>
#
# The archive brings no Composer resolution, so the packages a shop locks for its Shopware release must
# already satisfy the SDK's requirements. The script resolves the pinned SDK against the lowest and the
# newest release of every supported core line and fails when one of them cannot take it.
#
# Needs php, composer, jq and network access to Packagist; it runs the same in CI and in a lane container.

set -euo pipefail

readonly SDK_PACKAGES=(ucp-php-sdk/core ucp-php-sdk/symfony-bundle)
# The lowest and the newest release of each core line the plugin supports.
readonly CORE_CONSTRAINTS=(6.5.8.0 '~6.5.8' 6.6.0.0 '~6.6.0' 6.7.0.0 '~6.7.0')
# Composer resolves for the PHP that runs it. Left alone, it locks symfony/http-client 8 for a 6.5 shop,
# which needs PHP 8.4, newer than 6.5 supports.
readonly PLATFORM_PHP='8.3.0'
readonly LICENCE_FILE_PREDICATE=(-iname 'LICEN[CS]E*' -o -iname 'COPYING*')

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-bundle-sdk-into-archive.sh <plugin-dir>" >&2
  exit 1
fi

for tool in php composer jq; do
  if ! command -v "${tool}" >/dev/null 2>&1; then
    echo "Missing required dependency: ${tool}" >&2
    exit 1
  fi
done

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd "$1" && pwd)"
manifest="${plugin_dir}/composer.json"

if [[ ! -f "${manifest}" ]]; then
  echo "No composer.json in ${plugin_dir}" >&2
  exit 1
fi

fail() {
  echo "FAIL: $1" >&2
  exit 1
}

work_dir="$(mktemp -d)"
trap 'rm -rf "${work_dir}"' EXIT

# --- the pinned versions --------------------------------------------------------------------------

jq_requires=()
for package in "${SDK_PACKAGES[@]}"; do
  pinned_version="$(jq -r --arg package "${package}" '.require[$package] // empty' "${manifest}")"
  if [[ ! "${pinned_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    fail "composer.json must pin ${package} to an exact version, got '${pinned_version}'."
  fi
  jq_requires+=("${package}=${pinned_version}")
done
sdk_requires="$(jq -n '$ARGS.positional | map(split("=") | {key: .[0], value: .[1]}) | from_entries' --args "${jq_requires[@]}")"

# --- install the SDK into a throwaway project -----------------------------------------------------

# Only the two packages' sources are used, so an advisory on one of their dependencies must not stop the
# build, and platform requirements are ignored because the archive runs on the shop's PHP, not this one.
# composer/class-map-generator is for lib/preload-classes.php and never ships.
jq -n --argjson sdkRequires "${sdk_requires}" '
  {
    require: ($sdkRequires + {"composer/class-map-generator": "^1.0"}),
    config: {audit: {"block-insecure": false}}
  }' > "${work_dir}/composer.json"

composer install --working-dir="${work_dir}" --no-dev --no-interaction --no-progress --no-scripts \
  --no-plugins --ignore-platform-reqs --quiet

# --- check the SDK packages -----------------------------------------------------------------------

ext_requirements='{}'
for package in "${SDK_PACKAGES[@]}"; do
  source_dir="${work_dir}/vendor/${package}"
  sdk_manifest="${source_dir}/composer.json"
  if [[ ! -f "${sdk_manifest}" ]]; then
    fail "composer install did not provide ${package}."
  fi

  path_count="$(jq '[.autoload["psr-4"] // {} | .[] | if type == "array" then .[] else . end] | length' "${sdk_manifest}")"
  if [[ "${path_count}" != "1" ]]; then
    fail "${package} declares ${path_count} PSR-4 paths, expected exactly one."
  fi
  psr4_path="$(jq -r '.autoload["psr-4"] | to_entries[0].value' "${sdk_manifest}")"
  if [[ "${psr4_path}" != "src/" ]]; then
    fail "${package} maps its namespace to '${psr4_path}', but only src/ is bundled."
  fi

  ext_requirements="$(jq --slurpfile sdk "${sdk_manifest}" '
      . + (($sdk[0].require // {}) | with_entries(select(.key | startswith("ext-"))))' <<< "${ext_requirements}")"
done

# --- check the SDK against every supported core line ----------------------------------------------

# Core itself requires only part of what the SDK needs, for example symfony/http-client arrives through
# async-aws/core. A shop locks what core resolves to, so the SDK is added to that lock as a partial
# update: Composer keeps every locked package where it is, fails with the unmet requirement when one
# does not fit, and adds a package the lock lacks.
composer_flags=(--no-install --no-interaction --no-progress --no-scripts --no-plugins
  --ignore-platform-req='ext-*' --ignore-platform-req='lib-*')

check_sdk_against_core() {
  local constraint="$1"
  local core_dir core_version locked_names missing output
  core_dir="$(mktemp -d "${work_dir}/core.XXXXXX")"

  jq -n --arg constraint "${constraint}" --arg php "${PLATFORM_PHP}" '
    {
      require: {"shopware/core": $constraint},
      config: {audit: {"block-insecure": false}, platform: {php: $php}}
    }' > "${core_dir}/composer.json"
  if ! output="$(composer update --working-dir="${core_dir}" "${composer_flags[@]}" 2>&1)"; then
    echo "${output}" >&2
    fail "shopware/core ${constraint} could not be resolved."
  fi

  core_version="$(jq -r '.packages[] | select(.name == "shopware/core") | .version' "${core_dir}/composer.lock")"
  locked_names="$(jq '[.packages[].name]' "${core_dir}/composer.lock")"

  jq --argjson sdkRequires "${sdk_requires}" '.require += $sdkRequires' "${core_dir}/composer.json" > "${core_dir}/composer.json.sdk"
  mv "${core_dir}/composer.json.sdk" "${core_dir}/composer.json"
  if ! output="$(composer update --working-dir="${core_dir}" "${composer_flags[@]}" "${SDK_PACKAGES[@]}" 2>&1)"; then
    echo "${output}" >&2
    fail "the pinned SDK does not fit the packages shopware/core ${core_version} locks. The report above names the package."
  fi

  missing="$(jq -r --argjson lockedNames "${locked_names}" --argjson sdkRequires "${sdk_requires}" '
      .packages[].name
      | select(. as $name | ($lockedNames + ($sdkRequires | keys)) | index($name) | not)' "${core_dir}/composer.lock")"
  if [[ -n "${missing}" ]]; then
    fail "the pinned SDK needs packages that shopware/core ${core_version} does not lock: $(tr '\n' ' ' <<< "${missing}")"
  fi

  echo "ok: the pinned SDK fits the packages shopware/core ${core_version} locks (${constraint})."
}

for constraint in "${CORE_CONSTRAINTS[@]}"; do
  check_sdk_against_core "${constraint}"
done

# --- copy the sources -----------------------------------------------------------------------------

rm -rf "${plugin_dir:?}/lib"
psr4_entries='{}'
replace_entries='{}'
for package in "${SDK_PACKAGES[@]}"; do
  source_dir="${work_dir}/vendor/${package}"
  target_dir="${plugin_dir}/lib/${package}"
  mkdir -p "${target_dir}"

  cp -R "${source_dir}/src" "${target_dir}/src"
  if [[ -d "${source_dir}/resources" ]]; then
    cp -R "${source_dir}/resources" "${target_dir}/resources"
  fi
  cp "${source_dir}/composer.json" "${target_dir}/composer.json"
  find "${source_dir}" -maxdepth 1 -type f \( "${LICENCE_FILE_PREDICATE[@]}" \) -exec cp {} "${target_dir}/" \;

  installed_version="$(jq -r --arg package "${package}" '.packages[] | select(.name == $package) | .version | ltrimstr("v")' \
    "${work_dir}/vendor/composer/installed.json")"
  psr4_entries="$(jq --arg namespace "$(jq -r '.autoload["psr-4"] | keys[0]' "${source_dir}/composer.json")" \
    --arg path "lib/${package}/src/" '. + {($namespace): $path}' <<< "${psr4_entries}")"
  replace_entries="$(jq --arg package "${package}" --arg version "${installed_version}" '. + {($package): $version}' <<< "${replace_entries}")"
done

# --- rewrite the archive manifest -----------------------------------------------------------------

jq --indent 4 \
  --argjson sdkPackages "$(printf '%s\n' "${SDK_PACKAGES[@]}" | jq -R . | jq -s .)" \
  --argjson extRequirements "${ext_requirements}" \
  --argjson replaceEntries "${replace_entries}" \
  --argjson psr4Entries "${psr4_entries}" '
    .require |= (
      (. | del(.[$sdkPackages[]])) + $extRequirements
      | to_entries
      | sort_by([(if .key == "php" then 0 elif (.key | startswith("ext-")) then 1 else 2 end), .key])
      | from_entries)
    | .replace = $replaceEntries
    | .autoload["psr-4"] += $psr4Entries' "${manifest}" > "${work_dir}/archive-composer.json"
mv "${work_dir}/archive-composer.json" "${manifest}"

# --- the preload class list -----------------------------------------------------------------------

php "${script_dir}/write-preload-class-list.php" "${plugin_dir}" "${work_dir}/vendor/autoload.php"

echo "Bundled $(printf '%s ' "${SDK_PACKAGES[@]}")into ${plugin_dir}/lib and rewrote ${manifest}."
