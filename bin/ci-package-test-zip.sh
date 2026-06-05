#!/usr/bin/env bash
set -euo pipefail

for dependency in composer jq rsync shopware-cli unzip; do
  if ! command -v "${dependency}" >/dev/null 2>&1; then
    echo "Required dependency '${dependency}' is not available." >&2
    exit 1
  fi
done

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SDK_ROOT="${SDK_ROOT:-${PLUGIN_ROOT}/../ucp-php-sdk}"
OUTPUT_DIR="${1:-${PLUGIN_ROOT}/var/test-zip}"
ADMIN_PUBLIC_SOURCE="${ADMIN_PUBLIC_SOURCE:-${PLUGIN_ROOT}/var/package-admin-public}"

if [[ ! -d "${SDK_ROOT}/packages/core" || ! -d "${SDK_ROOT}/packages/symfony-bundle" ]]; then
  echo "SDK checkout not found at ${SDK_ROOT}." >&2
  exit 1
fi

if [[ ! -d "${ADMIN_PUBLIC_SOURCE}" ]]; then
  echo "Packaged administration assets not found at ${ADMIN_PUBLIC_SOURCE}." >&2
  exit 1
fi

if [[ ! -f "${ADMIN_PUBLIC_SOURCE}/administration/.vite/entrypoints.json" ]]; then
  echo "Vite administration entrypoints are missing from ${ADMIN_PUBLIC_SOURCE}/administration/.vite/entrypoints.json." >&2
  exit 1
fi

short_sha="${GITHUB_SHA:-$(git -C "${PLUGIN_ROOT}" rev-parse HEAD)}"
short_sha="${short_sha:0:12}"
package_version="${PACKAGE_VERSION:-0.0.1+${short_sha}}"
zip_name="SwagAgenticCommerce-main-${short_sha}.zip"
metadata_name="artifact-metadata.json"

stage_parent="$(mktemp -d "${TMPDIR:-/tmp}/swag-agentic-commerce-package.XXXXXX")"
stage_dir="${stage_parent}/SwagAgenticCommerce"

cleanup() {
  rm -rf "${stage_parent}"
}

trap cleanup EXIT

mkdir -p "${stage_dir}" "${OUTPUT_DIR}"

rsync -a --delete \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.claude' \
  --exclude='.tools' \
  --exclude='.eslintcache' \
  --exclude='.phpunit.cache' \
  --exclude='.phpunit.result.cache' \
  --exclude='composer.lock' \
  --exclude='coverage' \
  --exclude='node_modules' \
  --exclude='public/bundles' \
  --exclude='src/Resources/public' \
  --exclude='tests' \
  --exclude='var' \
  --exclude='vendor' \
  "${PLUGIN_ROOT}/" "${stage_dir}/"

mkdir -p "${stage_dir}/src/Resources/public"
rsync -a --delete "${ADMIN_PUBLIC_SOURCE}/" "${stage_dir}/src/Resources/public/"

printf 'bundled-sdk\n' >"${stage_dir}/.swag-agentic-commerce-bundled-sdk"

(
  cd "${stage_dir}"
  cp composer.json composer.json.release-source
  jq \
    --arg sdkCorePath "${SDK_ROOT}/packages/core" \
    --arg sdkSymfonyPath "${SDK_ROOT}/packages/symfony-bundle" \
    '
      .require = {
        "php": .require.php,
        "ucp-php-sdk/symfony-bundle": .require["ucp-php-sdk/symfony-bundle"]
      }
      | .config["vendor-dir"] = "vendor"
      | .repositories = [
        {
          "type": "path",
          "url": $sdkCorePath,
          "options": {
            "symlink": false,
            "versions": {
              "shopware/ucp-php-sdk-core": "0.0.1"
            }
          }
        },
        {
          "type": "path",
          "url": $sdkSymfonyPath,
          "options": {
            "symlink": false,
            "versions": {
              "ucp-php-sdk/symfony-bundle": "0.0.1"
            }
          }
        }
      ]
      | .replace = {
        "doctrine/dbal": "*",
        "symfony/config": "*",
        "symfony/dependency-injection": "*",
        "symfony/event-dispatcher-contracts": "*",
        "symfony/framework-bundle": "*",
        "symfony/http-client": "*",
        "symfony/http-foundation": "*",
        "symfony/http-kernel": "*",
        "symfony/routing": "*"
      }
      | del(."require-dev", ."autoload-dev", .scripts)
    ' composer.json.release-source >composer.json
  composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader
  jq \
    --arg packageVersion "${package_version}" \
    '.version = $packageVersion | .config["vendor-dir"] = "vendor" | del(.repositories)' \
    composer.json.release-source >composer.json
  rm -f composer.json.release-source
)

rm -f "${stage_dir}/composer.lock"
rm -rf \
  "${stage_dir}/vendor/shopware/ucp-php-sdk-core/tests" \
  "${stage_dir}/vendor/ucp-php-sdk/symfony-bundle/tests"

if find "${stage_dir}/vendor" -mindepth 1 -maxdepth 1 -type d \( -name doctrine -o -name symfony \) | grep -q .; then
  echo "Packaged vendor must not include Shopware-provided Symfony or Doctrine packages." >&2
  exit 1
fi

for required_path in \
  "${stage_dir}/vendor/shopware/ucp-php-sdk-core/src" \
  "${stage_dir}/vendor/ucp-php-sdk/symfony-bundle/src" \
  "${stage_dir}/vendor/autoload.php" \
  "${stage_dir}/.swag-agentic-commerce-bundled-sdk" \
  "${stage_dir}/src/Resources/public/administration/.vite/entrypoints.json"; do
  if [[ ! -e "${required_path}" ]]; then
    echo "Packaged artifact is missing ${required_path}." >&2
    exit 1
  fi
done

rm -f "${OUTPUT_DIR}/${zip_name}" "${OUTPUT_DIR}/${zip_name}.sha256" "${OUTPUT_DIR}/${metadata_name}"

shopware-cli extension zip "${stage_dir}" "main-${short_sha}" \
  --disable-git \
  --release \
  --overwrite-version "${package_version}" \
  --filename "${zip_name}" \
  --output-directory "${OUTPUT_DIR}"

zip_path="${OUTPUT_DIR}/${zip_name}"
zip_listing="$(unzip -l "${zip_path}")"

if ! grep -Eq 'SwagAgenticCommerce/vendor/shopware/ucp-php-sdk-core/src/.+\.php' <<<"${zip_listing}"; then
  echo "Artifact is missing SDK core sources." >&2
  exit 1
fi

if ! grep -Eq 'SwagAgenticCommerce/vendor/ucp-php-sdk/symfony-bundle/src/.+\.php' <<<"${zip_listing}"; then
  echo "Artifact is missing SDK Symfony bundle sources." >&2
  exit 1
fi

if ! grep -q 'SwagAgenticCommerce/.swag-agentic-commerce-bundled-sdk' <<<"${zip_listing}"; then
  echo "Artifact is missing the bundled SDK marker." >&2
  exit 1
fi

if grep -Eq 'SwagAgenticCommerce/(\.git|\.github|\.claude|\.tools|\.phpunit\.cache|coverage|node_modules|tests|var)/|SwagAgenticCommerce/(\.eslintcache|\.phpunit\.result\.cache|composer\.lock)|SwagAgenticCommerce/vendor/(shopware/ucp-php-sdk-core|ucp-php-sdk/symfony-bundle)/tests/|SwagAgenticCommerce/vendor/(doctrine|symfony)/' <<<"${zip_listing}"; then
  echo "Artifact contains excluded development files." >&2
  exit 1
fi

if command -v sha256sum >/dev/null 2>&1; then
  (
    cd "${OUTPUT_DIR}"
    sha256sum "${zip_name}" >"${zip_name}.sha256"
  )
else
  (
    cd "${OUTPUT_DIR}"
    shasum -a 256 "${zip_name}" >"${zip_name}.sha256"
  )
fi

jq -n \
  --arg pluginSha "$(git -C "${PLUGIN_ROOT}" rev-parse HEAD)" \
  --arg sdkSha "$(git -C "${SDK_ROOT}" rev-parse HEAD)" \
  --arg sdkRef "${UCP_SDK_REF:-}" \
  --arg packageVersion "${package_version}" \
  --arg zipFile "${zip_name}" \
  --arg shopware65Ref "${SHOPWARE_65_REF:-}" \
  --arg shopware66Ref "${SHOPWARE_66_REF:-}" \
  --arg shopwareTrunkRef "${SHOPWARE_TRUNK_REF:-}" \
  '{
    plugin_sha: $pluginSha,
    sdk_sha: $sdkSha,
    sdk_ref: $sdkRef,
    package_version: $packageVersion,
    zip_file: $zipFile,
    shopware_refs: {
      "6.5.x": $shopware65Ref,
      "6.6.x": $shopware66Ref,
      trunk: $shopwareTrunkRef
    }
  }' >"${OUTPUT_DIR}/${metadata_name}"

echo "Created ${zip_path}"
