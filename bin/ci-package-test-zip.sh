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

legacy_admin_bootstrap="${PLUGIN_ROOT}/src/Resources/app/administration/src/public/js/swag-agentic-commerce.js"

if [[ ! -f "${legacy_admin_bootstrap}" ]]; then
  echo "Legacy administration bootstrap is missing at ${legacy_admin_bootstrap}." >&2
  exit 1
fi

short_sha="${GITHUB_SHA:-$(git -C "${PLUGIN_ROOT}" rev-parse HEAD)}"
short_sha="${short_sha:0:12}"

# Derive version from a version tag (e.g. v1.2.3 → 1.2.3) when not explicitly set.
if [[ -z "${PACKAGE_VERSION:-}" ]]; then
  ref_name="${GITHUB_REF_NAME:-}"
  if [[ "${ref_name}" =~ ^v([0-9]+\.[0-9]+\.[0-9]+.*)$ ]]; then
    PACKAGE_VERSION="${BASH_REMATCH[1]}"
  fi
fi
package_version="${PACKAGE_VERSION:-0.0.1+${short_sha}}"

# Release builds get a version-based filename; dev builds keep the sha-based name.
if [[ "${package_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then
  zip_name="SwagAgenticCommerce-${package_version}.zip"
else
  zip_name="SwagAgenticCommerce-main-${short_sha}.zip"
fi
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
mkdir -p "${stage_dir}/src/Resources/public/administration/js"
cp "${legacy_admin_bootstrap}" "${stage_dir}/src/Resources/public/administration/js/swag-agentic-commerce.js"

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
              "ucp-php-sdk/core": "0.0.1"
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
        "shopware/core": "*",
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
  # Shopware loads this bundled vendor/ via SwagAgenticCommerce::getAdditionalBundles(), which
  # registers its vendor/composer/installed.php as an additional Composer runtime dataset. The
  # .replace map above records the platform packages (symfony/*, shopware/core, doctrine/dbal) as
  # "replaced" with no version. Composer's InstalledVersions::getPrettyVersion() returns null for
  # the first registered dataset that mentions a package, so once this dataset is active
  # getPrettyVersion('symfony/http-kernel') resolves to null; Symfony 6.6/6.7's profiler
  # ConfigDataCollector then falls back to the absent 'symfony/symfony' and throws, turning every
  # profiled UCP request (e.g. /.well-known/ucp) into a 500. Drop the replaced-only entries so the
  # bundled dataset defers to Shopware's root registry for those packages.
  php -r '
    $file = "vendor/composer/installed.php";
    $data = require $file;
    foreach ($data["versions"] as $name => $info) {
      if (array_key_exists("replaced", $info) && !array_key_exists("version", $info)) {
        unset($data["versions"][$name]);
      }
    }
    file_put_contents($file, "<?php return " . var_export($data, true) . ";" . PHP_EOL);
  '
  if grep -q 'symfony/http-kernel' vendor/composer/installed.php; then
    echo "Bundled vendor/composer/installed.php still lists replaced platform packages; the profiler InstalledVersions guard failed." >&2
    exit 1
  fi
  jq \
    --arg packageVersion "${package_version}" \
    '.version = $packageVersion
     | .config["vendor-dir"] = "vendor"' \
    composer.json.release-source >composer.json
  rm -f composer.json.release-source
)

rm -f "${stage_dir}/composer.lock"
rm -rf \
  "${stage_dir}/vendor/ucp-php-sdk/core/tests" \
  "${stage_dir}/vendor/ucp-php-sdk/symfony-bundle/tests"
find "${stage_dir}" \( -name '.DS_Store' -o -name '._*' \) -delete

if find "${stage_dir}/vendor" -mindepth 1 -maxdepth 1 -type d \( -name doctrine -o -name symfony \) | grep -q .; then
  echo "Packaged vendor must not include Shopware-provided Symfony or Doctrine packages." >&2
  exit 1
fi

for required_path in \
  "${stage_dir}/vendor/ucp-php-sdk/core/src" \
  "${stage_dir}/vendor/ucp-php-sdk/symfony-bundle/src" \
  "${stage_dir}/vendor/autoload.php" \
  "${stage_dir}/.swag-agentic-commerce-bundled-sdk" \
  "${stage_dir}/src/Resources/public/administration/.vite/entrypoints.json" \
  "${stage_dir}/src/Resources/public/administration/js/swag-agentic-commerce.js"; do
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

if ! grep -Eq 'SwagAgenticCommerce/vendor/ucp-php-sdk/core/src/.+\.php' <<<"${zip_listing}"; then
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

if ! grep -q 'SwagAgenticCommerce/src/Resources/public/administration/js/swag-agentic-commerce.js' <<<"${zip_listing}"; then
  echo "Artifact is missing the legacy administration bootstrap." >&2
  exit 1
fi

if grep -Eq 'SwagAgenticCommerce/(\.git|\.github|\.claude|\.tools|\.phpunit\.cache|coverage|node_modules|tests|var)/|SwagAgenticCommerce/(\.DS_Store|._[^/]*|\.eslintcache|\.phpunit\.result\.cache|composer\.lock)|SwagAgenticCommerce/vendor/(ucp-php-sdk/core|ucp-php-sdk/symfony-bundle)/tests/|SwagAgenticCommerce/vendor/(doctrine|symfony)/' <<<"${zip_listing}"; then
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
