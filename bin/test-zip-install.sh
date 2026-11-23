#!/usr/bin/env bash
#
# Installs a built extension ZIP into a running Shopware the way a merchant does -- through the
# admin upload endpoint -- on a shop that does NOT already provide the UCP SDK. With --from-zip it
# first installs an earlier archive and then updates it to the ZIP under test.
#
# The script refuses to run when the shop can still resolve Ucp\Sdk\... before it starts, because
# the archive's own bundled copy would then never be exercised and a pass would prove nothing.
#
# After the install or update it fails when
#   - any Composer package outside shopware/agentic-commerce and ucp-php-sdk/* was added, removed
#     or changed in composer.lock or vendor/composer/installed.json, or the shop's composer.json
#     still requires shopware/agentic-commerce,
#   - a directory directly under vendor/ or vendor/<vendor>/ is empty,
#   - the storefront, the Administration or /.well-known/ucp does not answer HTTP 200.
#
# Exit codes: 0 passed, 1 the archive under test failed a check, 2 wrong usage, 3 the shop or the
# base archive could not be set up, so the archive under test was not exercised.
#
# Usage:
#   bin/test-zip-install.sh --zip <file> --container <name> --shop-url <url> [--from-zip <file>]
#                           [--older-packages] [--admin-user admin] [--admin-pass shopware]
#                           [--exec-user www-data] [--work-dir <dir>] [--keep]
#                           [--sync-session <mutagen session>]
#
# --older-packages    The shop has older Symfony packages. A --from-zip archive that Shopware has
#                     to install through Composer (1.4.0) is then required on the command line,
#                     because installing it through the Administration is the defect.
# --exec-user         User of the docker exec calls. Defaults to www-data; a shop whose web
#                     container runs as the host user needs that user's uid.
#
# Example (sw-dev lane 6.5):
#   bin/test-zip-install.sh --zip ~/Downloads/SwagAgenticCommerce-1.3.99.zip \
#     --container shopware-6-5-branch-web-1 --shop-url http://sw65.localhost:8088 \
#     --sync-session agentic-commerce-65
#
set -euo pipefail

PLUGIN="SwagAgenticCommerce"
SDK_PROBE_CLASS='Ucp\Sdk\Symfony\UcpSdkBundle'
BUNDLED_SDK_MARKER=".swag-agentic-commerce-bundled-sdk"
OWN_PACKAGES='^(shopware/agentic-commerce|ucp-php-sdk/.+)$'

zip_file=""
from_zip=""
container=""
shop_url=""
admin_user="admin"
admin_pass="shopware"
exec_user="www-data"
work_dir=""
is_kept=0
has_older_packages=0
sync_session=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --zip) zip_file="$2"; shift 2 ;;
    --from-zip) from_zip="$2"; shift 2 ;;
    --container) container="$2"; shift 2 ;;
    --shop-url) shop_url="${2%/}"; shift 2 ;;
    --admin-user) admin_user="$2"; shift 2 ;;
    --admin-pass) admin_pass="$2"; shift 2 ;;
    --exec-user) exec_user="$2"; shift 2 ;;
    --work-dir) work_dir="$2"; shift 2 ;;
    --keep) is_kept=1; shift ;;
    --older-packages) has_older_packages=1; shift ;;
    --sync-session) sync_session="$2"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

for required in zip_file container shop_url; do
  if [[ -z "${!required}" ]]; then
    echo "Missing --${required//_/-}. See the usage comment at the top of this script." >&2
    exit 2
  fi
done

[[ -f "${zip_file}" ]] || { echo "No such zip: ${zip_file}" >&2; exit 2; }
if [[ -n "${from_zip}" && ! -f "${from_zip}" ]]; then
  echo "No such zip: ${from_zip}" >&2
  exit 2
fi

if [[ -z "${work_dir}" ]]; then
  work_dir="$(mktemp -d)"
fi
mkdir -p "${work_dir}"
work_dir="$(cd "${work_dir}" && pwd)"

in_shop() { docker exec -u "${exec_user}" "${container}" sh -c "cd /var/www/html && $1"; }

say() { printf '  %s\n' "$1"; }

setup_failure() {
  echo "SETUP FAILURE: $1" >&2
  echo "  The archive under test was not exercised." >&2
  exit 3
}

fail_step() {
  if [[ "$1" == "base" ]]; then
    setup_failure "$2"
  fi
  echo "FAIL: $2" >&2
  check_composer_state || true
  exit 1
}

api() { curl -sS -o "$2" -w '%{http_code}' --max-time 300 -H "Authorization: Bearer ${token}" "${@:3}"; }

archive_version() { unzip -p "$1" "${PLUGIN}/composer.json" | jq -r '.version'; }

# 1.4.0 ships neither the bundled SDK nor the marker of 1.3.0, so Shopware has to install it
# through Composer.
archive_needs_composer() {
  local listing
  listing="$(unzip -Z1 "$1")"
  ! grep -qE "^${PLUGIN}/(lib/ucp-php-sdk/|\\${BUNDLED_SDK_MARKER}\$)" <<< "${listing}"
}

# Authenticate before temporarily removing a plugin that may already be active.
token=$(curl -sS -X POST "${shop_url}/api/oauth/token" -H 'Content-Type: application/json' \
  -d "{\"client_id\":\"administration\",\"grant_type\":\"password\",\"scopes\":\"write\",\"username\":\"${admin_user}\",\"password\":\"${admin_pass}\"}" \
  | jq -r '.access_token // empty' || true)
[[ -n "${token}" ]] || setup_failure "Could not obtain an admin token from ${shop_url}."

# ---------------------------------------------------------------------------------------------
# Restore. Registered before anything is moved, so an interrupted run still puts the lane back.
# ---------------------------------------------------------------------------------------------
restored=0
composer_backed_up=0
registry_backed_up=0
plugin_moved=0
sdk_moved=0
archive_present=0
# Invoked from the EXIT trap below, which shellcheck cannot see.
# shellcheck disable=SC2317,SC2329
restore() {
  if [[ "${restored}" -eq 1 ]]; then
    return 0
  fi
  restored=1
  if [[ "${is_kept}" -eq 1 ]]; then
    say "shop left as it is (--keep)"
    return 0
  fi
  echo "== restoring the lane"
  if [[ "${composer_backed_up}" -eq 1 ]]; then
    in_shop "cp /tmp/zit-composer.json composer.json && cp /tmp/zit-composer.lock composer.lock" || true
  fi
  if [[ "${registry_backed_up}" -eq 1 ]]; then
    in_shop "cp /tmp/zit-installed.json vendor/composer/installed.json && cp /tmp/zit-installed.php vendor/composer/installed.php" || true
  fi
  if [[ "${plugin_moved}" -eq 1 ]]; then
    in_shop "rm -rf custom/plugins/${PLUGIN} && mv /tmp/zit-plugin custom/plugins/${PLUGIN}" || true
  elif [[ "${archive_present}" -eq 1 ]]; then
    # The lane had no plugin when this started, so putting it back means taking the archive out
    # again -- files and plugin record both. Every step is best-effort: the archive may have been
    # extracted by the upload and never installed, which is the case this whole script exists for.
    api PUT /dev/null -X PUT "${shop_url}/api/_action/extension/deactivate/plugin/${PLUGIN}" >/dev/null || true
    api POST /dev/null -X POST "${shop_url}/api/_action/extension/uninstall/plugin/${PLUGIN}" >/dev/null || true
    in_shop "rm -rf custom/plugins/${PLUGIN}" || true
    api POST /dev/null -X POST "${shop_url}/api/_action/extension/refresh" >/dev/null || true
    in_shop "php bin/console cache:clear --no-warmup" >/dev/null 2>&1 || true
  fi
  if [[ "${sdk_moved}" -eq 1 ]]; then
    in_shop "rm -rf vendor/ucp-php-sdk && mv /tmp/zit-sdk vendor/ucp-php-sdk" || true
  fi
  if [[ -n "${sync_session}" ]]; then
    mutagen sync resume "${sync_session}" >/dev/null 2>&1 || true
  fi
  if [[ "${plugin_moved}" -eq 1 ]]; then
    say "lane restored; re-run your bootstrap if the plugin version looks off"
  elif [[ "${archive_present}" -eq 1 ]]; then
    say "archive taken back out; the lane has no plugin again, as it did before this ran"
  else
    say "nothing was moved, so there is nothing to restore"
  fi
}
trap restore EXIT

# ---------------------------------------------------------------------------------------------
echo "== preparing a shop that has never seen the SDK"

if [[ -n "${sync_session}" ]]; then
  if mutagen sync pause "${sync_session}" >/dev/null 2>&1; then
    say "paused sync ${sync_session}"
  fi
fi

in_shop "test ! -e /tmp/zit-plugin && test ! -e /tmp/zit-sdk"
in_shop "cp composer.json /tmp/zit-composer.json && cp composer.lock /tmp/zit-composer.lock"
composer_backed_up=1
in_shop "cp vendor/composer/installed.json /tmp/zit-installed.json && cp vendor/composer/installed.php /tmp/zit-installed.php"
registry_backed_up=1

# A development lane also registers the plugin and SDK in Composer's package registry.
# Leaving those entries behind makes Shopware treat the ZIP as Composer-managed and
# skip its shipped dependencies, even though the registered SDK files were moved away.
docker exec -i -u "${exec_user}" "${container}" php <<'PHP'
<?php
chdir('/var/www/html');
$names = ['shopware/agentic-commerce', 'ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'];
$manifest = json_decode(file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['require', 'require-dev'] as $section) {
    foreach ($names as $name) {
        unset($manifest[$section][$name]);
    }
}
file_put_contents('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$installed = json_decode(file_get_contents('vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$installed['packages'] = array_values(array_filter($installed['packages'], static fn (array $package): bool => !in_array($package['name'], $names, true)));
file_put_contents('vendor/composer/installed.json', json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$versions = require 'vendor/composer/installed.php';
foreach ($names as $name) {
    unset($versions['versions'][$name]);
}
file_put_contents('vendor/composer/installed.php', '<?php return '.var_export($versions, true).';');
PHP

# A development lane has the plugin source checked out here; the shops of the zip-install job have
# none. Either way what must not be present when the archive is uploaded is a second copy of the
# plugin.
if in_shop "test -d custom/plugins/${PLUGIN}"; then
  in_shop "mv custom/plugins/${PLUGIN} /tmp/zit-plugin"
  plugin_moved=1
fi
if in_shop "test -d vendor/ucp-php-sdk"; then
  in_shop "mv vendor/ucp-php-sdk /tmp/zit-sdk"
  sdk_moved=1
fi
say "moved aside: plugin source (${plugin_moved}), project-level SDK (${sdk_moved})"

# The refusal. If the shop still resolves the SDK, a successful install proves nothing about the
# archive -- which is exactly how the shipped defect stayed invisible.
probe=$(in_shop "php -r 'require \"vendor/autoload.php\"; echo class_exists(\"${SDK_PROBE_CLASS//\\/\\\\}\") ? \"yes\" : \"no\";'" 2>/dev/null || echo "error")
if [[ "${probe}" != "no" ]]; then
  echo "REFUSING TO RUN: the shop can still resolve ${SDK_PROBE_CLASS} (probe: ${probe})." >&2
  echo "  The archive's own bundled copy would never be exercised, so a pass would be" >&2
  echo "  meaningless. Remove the project-level SDK first." >&2
  exit 3
fi
say "confirmed: ${SDK_PROBE_CLASS} is NOT resolvable by the shop"

capture_composer_state() {
  in_shop "cat composer.json" > "${work_dir}/$1-composer.json"
  in_shop "cat composer.lock" > "${work_dir}/$1-composer.lock"
  in_shop "cat vendor/composer/installed.json" > "${work_dir}/$1-installed.json"
}

changed_packages() {
  jq -rn --slurpfile before "$1" --slurpfile after "$2" --arg own "${OWN_PACKAGES}" '
    def packages: ((.packages // []) + (.["packages-dev"] // []))
      | map({key: .name, value: [.version, (.dist.reference // null)]}) | from_entries;
    ($before[0] | packages) as $was | ($after[0] | packages) as $now
    | ($was + $now | keys[])
    | select(test($own) | not)
    | select($was[.] != $now[.])
    | "\(.): \($was[.] | tojson) -> \($now[.] | tojson)"'
}

check_composer_state() {
  local has_changed=0 moved_in_lock moved_in_registry empty_directories
  local manifest_without_plugin='del(.require["shopware/agentic-commerce"])'
  capture_composer_state after

  moved_in_lock="$(changed_packages "${work_dir}/before-composer.lock" "${work_dir}/after-composer.lock")"
  moved_in_registry="$(changed_packages "${work_dir}/before-installed.json" "${work_dir}/after-installed.json")"
  if [[ -z "${moved_in_lock}" && -z "${moved_in_registry}" ]]; then
    say "no package outside the plugin's own moved in composer.lock or vendor/composer/installed.json"
  else
    echo "FAIL: the install or update moved packages outside shopware/agentic-commerce and ucp-php-sdk/*." >&2
    if [[ -n "${moved_in_lock}" ]]; then
      echo "      composer.lock:" >&2
      printf '%s\n' "${moved_in_lock}" | sed 's/^/        /' >&2
    fi
    if [[ -n "${moved_in_registry}" ]]; then
      echo "      vendor/composer/installed.json:" >&2
      printf '%s\n' "${moved_in_registry}" | sed 's/^/        /' >&2
    fi
    has_changed=1
  fi

  if [[ "$(jq -S "${manifest_without_plugin}" "${work_dir}/before-composer.json")" != "$(jq -S "${manifest_without_plugin}" "${work_dir}/after-composer.json")" ]]; then
    echo "FAIL: the install or update changed the shop's composer.json beyond the plugin's own requirement." >&2
    has_changed=1
  elif jq -e '.require["shopware/agentic-commerce"]' "${work_dir}/after-composer.json" >/dev/null; then
    echo "FAIL: the shop's composer.json requires shopware/agentic-commerce after the install or update." >&2
    has_changed=1
  else
    say "composer.json is unchanged and does not require the plugin"
  fi

  empty_directories="$(in_shop "find vendor -mindepth 1 -maxdepth 2 -type d -empty")"
  if [[ -z "${empty_directories}" ]]; then
    say "no empty directory under vendor/ or vendor/<vendor>/"
  else
    echo "FAIL: vendor/ has empty directories:" >&2
    printf '%s\n' "${empty_directories}" | sed 's/^/        /' >&2
    has_changed=1
  fi

  return "${has_changed}"
}

upload_archive() {
  local archive="$1" role="$2" upload_http_code
  api POST /dev/null -X POST "${shop_url}/api/_action/extension/refresh" >/dev/null
  # Set before the upload, not after a successful install: the upload extracts the archive into
  # custom/plugins/ and refreshes the plugin record, so from here on the lane holds a copy whether
  # or not anything below succeeds.
  archive_present=1
  upload_http_code=$(api POST "${work_dir}/upload.json" -X POST "${shop_url}/api/_action/extension/upload" -F "file=@${archive};type=application/zip")
  if [[ "${upload_http_code}" != "204" ]]; then
    report_error_detail "${work_dir}/upload.json"
    # The upload request still runs the installed release after the archive is extracted. The
    # files are in place and the update succeeds, but the two releases before the update
    # protection can answer 500 here:
    # - 1.3.0 always: it loads the SDK from its own vendor/ucp-php-sdk, which the archive replaces
    #   with lib/ucp-php-sdk, so Ucp\Sdk\Symfony\EventListener\IdempotencyResponseListener is
    #   not found.
    # - 1.4.0 sometimes, in dev only: the profiler builds Twig with 1.4.0's container, which
    #   passes UcpAgenticFilesExtension fewer arguments than the extracted class takes.
    # Any other base must answer 204.
    if [[ "${role}" != "test" || ! "${base_version}" =~ ^1\.(3|4)\.0$ || "${upload_http_code}" != "500" ]]; then
      fail_step "${role}" "upload returned HTTP ${upload_http_code}."
    fi
  fi
  say "upload: HTTP ${upload_http_code}"
  api POST /dev/null -X POST "${shop_url}/api/_action/extension/refresh" >/dev/null
}

report_error_detail() {
  {
    jq -r '"      " + ((.errors[0].detail // "") | tostring | .[0:400])' "$1" 2>/dev/null \
      || { printf '      '; sed -e 's/<[^>]*>//g' "$1" | tr '\n' ' ' | head -c 400; echo; }
  } >&2 || true
}

install_through_admin() {
  local archive="$1" role="$2" install_http_code activate_http_code
  upload_archive "${archive}" "${role}"

  install_http_code=$(api POST "${work_dir}/install.json" -X POST "${shop_url}/api/_action/extension/install/plugin/${PLUGIN}")
  if [[ "${install_http_code}" != "204" ]]; then
    report_error_detail "${work_dir}/install.json"
    fail_step "${role}" "install returned HTTP ${install_http_code}."
  fi
  say "install: HTTP 204"

  activate_http_code=$(api PUT /dev/null -X PUT "${shop_url}/api/_action/extension/activate/plugin/${PLUGIN}")
  [[ "${activate_http_code}" == "204" ]] || fail_step "${role}" "activate returned HTTP ${activate_http_code}."
  say "activate: HTTP 204"

  in_shop "php bin/console cache:clear --no-warmup" > "${work_dir}/cache-clear.log" 2>&1
}

update_through_admin() {
  local archive="$1" update_http_code
  upload_archive "${archive}" test

  update_http_code=$(api POST "${work_dir}/update.json" -X POST "${shop_url}/api/_action/extension/update/plugin/${PLUGIN}")
  if [[ "${update_http_code}" != "204" ]]; then
    report_error_detail "${work_dir}/update.json"
    fail_step test "update returned HTTP ${update_http_code}."
  fi
  say "update: HTTP 204"
}

# What a shop gets whose Composer files name the plugin: the extracted archive required with its
# SDK, without -W, so every locked package stays where it is.
install_through_composer() {
  local archive="$1" version
  version="$(archive_version "${archive}")"
  archive_present=1
  docker cp "${archive}" "${container}:/tmp/zit-base.zip"
  in_shop "php -r '\$zip = new ZipArchive(); \$zip->open(\"/tmp/zit-base.zip\") === true || exit(1); \$zip->extractTo(\"custom/plugins\") || exit(1);'" \
    || setup_failure "the base archive could not be extracted."
  capture_composer_state base-before
  in_shop "composer require --no-scripts --no-interaction shopware/agentic-commerce:${version}" \
    || setup_failure "composer require shopware/agentic-commerce:${version} failed."
  in_shop "php bin/console plugin:refresh && php bin/console plugin:install --activate ${PLUGIN} && php bin/console cache:clear --no-warmup" \
    || setup_failure "plugin:install of the base failed."
  capture_composer_state base-after
  local moved
  moved="$(changed_packages "${work_dir}/base-before-composer.lock" "${work_dir}/base-after-composer.lock")"
  if [[ -n "${moved}" ]]; then
    printf '      %s\n' "${moved}" >&2
    setup_failure "requiring the base moved locked packages."
  fi
  say "base required through Composer, locked packages unchanged"
}

http_failures=0
check_http() {
  local path status
  http_failures=0
  for path in / /admin /.well-known/ucp; do
    status=$(curl -sS -o "${work_dir}/http-body.txt" -w '%{http_code}' --max-time 300 "${shop_url}${path}" || true)
    if [[ "${status}" == "200" ]]; then
      say "GET ${path}: HTTP 200"
      if [[ "${path}" == "/.well-known/ucp" ]]; then
        cp "${work_dir}/http-body.txt" "${work_dir}/profile.json"
        say "$(jq -r '"discovery: version \(.ucp.version), \(.ucp.capabilities // {} | length) capabilities"' "${work_dir}/profile.json" 2>/dev/null || echo "discovery: not JSON")"
      fi
    else
      echo "FAIL: GET ${path} returned HTTP ${status:-none}, expected 200." >&2
      http_failures=$((http_failures + 1))
    fi
  done
}

lookup_plugin() {
  api POST "${work_dir}/plugin.json" -X POST "${shop_url}/api/search/plugin" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"filter\":[{\"type\":\"equals\",\"field\":\"name\",\"value\":\"${PLUGIN}\"}]}"
}

base_version=""
if [[ -n "${from_zip}" ]]; then
  base_version="$(archive_version "${from_zip}")"
  echo "== installing the base archive ${base_version}"
  if [[ "${has_older_packages}" -eq 1 ]] && archive_needs_composer "${from_zip}"; then
    say "${base_version} needs Composer to install and the shop has older packages, so it is required on the command line"
    install_through_composer "${from_zip}"
  else
    install_through_admin "${from_zip}" base
  fi

  check_http
  [[ "${http_failures}" -eq 0 ]] || setup_failure "the shop does not answer 200 after the base ${base_version} install."
  plugin_lookup_http_code=$(lookup_plugin)
  if [[ "${plugin_lookup_http_code}" != "200" || "$(jq -r '.data[0].active // false' "${work_dir}/plugin.json")" != "true" \
    || "$(jq -r '.data[0].version // ""' "${work_dir}/plugin.json")" != "${base_version}" ]]; then
    setup_failure "the base ${base_version} is not active at that version (search: HTTP ${plugin_lookup_http_code})."
  fi
  say "base ${base_version} is active"
fi

uploaded_zip="${zip_file}"
tested_version="$(archive_version "${zip_file}")"
if [[ -n "${from_zip}" ]]; then
  # Shopware offers an update only to a higher version, and a pull request's archive carries the
  # version of the release it is built on.
  base_core="${base_version%%-*}"
  tested_core="${tested_version%%-*}"
  if [[ "$(printf '%s\n' "${base_core}" "${tested_core}" | sort -V | tail -n 1)" == "${base_core}" ]]; then
    IFS=. read -r major minor patch <<< "${base_core}"
    tested_version="${major}.${minor}.$((patch + 1))"
    uploaded_zip="${work_dir}/${PLUGIN}-${tested_version}.zip"
    mkdir -p "${work_dir}/restamp/${PLUGIN}"
    cp "${zip_file}" "${uploaded_zip}"
    unzip -p "${zip_file}" "${PLUGIN}/composer.json" | jq --arg version "${tested_version}" '.version = $version' \
      > "${work_dir}/restamp/${PLUGIN}/composer.json"
    (cd "${work_dir}/restamp" && zip -q "${uploaded_zip}" "${PLUGIN}/composer.json")
    say "restamped the archive to ${tested_version}, as ${base_version} is installed"
  fi
  echo "== updating ${base_version} to ${tested_version} through the admin upload endpoint"
else
  echo "== installing the archive through the admin upload endpoint"
fi

capture_composer_state before
if [[ -n "${from_zip}" ]]; then
  update_through_admin "${uploaded_zip}"
else
  install_through_admin "${uploaded_zip}" test
fi

# ---------------------------------------------------------------------------------------------
echo "== checking it runs from the copy the archive shipped, without a Composer run"

has_failed=0

check_http
if [[ "${http_failures}" -gt 0 ]]; then
  has_failed=1
fi

bundle_bytes=$(curl -sS -o /dev/null -w '%{size_download}' --max-time 120 \
  "${shop_url}/bundles/swagagenticcommerce/administration/js/swag-agentic-commerce.js")
if [[ "${bundle_bytes}" -gt 50000 ]]; then
  say "admin bundle: ${bundle_bytes} bytes served without a build step"
else
  echo "FAIL: the administration bundle is ${bundle_bytes} bytes." >&2
  has_failed=1
fi

for sdk_package in core symfony-bundle; do
  if in_shop "test -f custom/plugins/${PLUGIN}/lib/ucp-php-sdk/${sdk_package}/composer.json"; then
    say "the extracted plugin carries lib/ucp-php-sdk/${sdk_package}"
  else
    echo "FAIL: the extracted plugin has no lib/ucp-php-sdk/${sdk_package}." >&2
    has_failed=1
  fi
done

if in_shop "test -e vendor/ucp-php-sdk"; then
  echo "FAIL: vendor/ucp-php-sdk is in the shop, so something ran Composer for the plugin." >&2
  has_failed=1
else
  say "the shop's vendor/ has no ucp-php-sdk"
fi

if in_shop "test -e vendor/shopware/agentic-commerce"; then
  echo "FAIL: vendor/shopware/agentic-commerce is in the shop, so Shopware required the plugin through Composer." >&2
  echo "      executeComposerCommands() must return false for the archive." >&2
  has_failed=1
else
  say "Shopware did not require the plugin through Composer"
fi

if in_shop "test -e custom/plugins/${PLUGIN}/vendor"; then
  echo "FAIL: the installed plugin has a vendor/ directory." >&2
  echo "      Its autoloader would register a second Composer ClassLoader in the shop." >&2
  has_failed=1
else
  say "the installed plugin carries no vendor/ of its own"
fi

check_composer_state || has_failed=1

datasets=$(in_shop "php -r 'require \"vendor/autoload.php\"; echo count(Composer\\InstalledVersions::getAllRawData());'" 2>/dev/null || echo "error")
if [[ "${datasets}" == "1" ]]; then
  say "Composer reports a single registered autoloader dataset"
else
  echo "FAIL: Composer reports ${datasets} autoloader datasets, expected 1." >&2
  has_failed=1
fi

plugin_lookup_http_code=$(lookup_plugin)
if [[ "${plugin_lookup_http_code}" == "200" && "$(jq -r '.data[0].active // false' "${work_dir}/plugin.json")" == "true" ]]; then
  say "the plugin is active"
else
  echo "FAIL: the plugin is not active after install and activate (search: HTTP ${plugin_lookup_http_code})." >&2
  has_failed=1
fi

installed_version="$(jq -r '.data[0].version // ""' "${work_dir}/plugin.json")"
if [[ "${installed_version}" == "${tested_version}" ]]; then
  say "the installed version is ${installed_version}"
else
  echo "FAIL: the plugin is at version '${installed_version}', expected ${tested_version}." >&2
  has_failed=1
fi

if [[ "${has_failed}" -eq 0 ]]; then
  echo "== PASS: the archive installs and runs from its own SDK without a Composer run"
fi

exit "${has_failed}"
