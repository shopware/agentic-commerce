#!/usr/bin/env bash
#
# Installs a built extension ZIP into a running Shopware the way a merchant does -- through the
# admin upload endpoint -- on a shop that does NOT already provide the UCP SDK.
#
# This exists because of a defect that shipped. The archive vendors the SDK into the plugin's own
# vendor/, Shopware never loads that autoloader, and `plugin:install` therefore died with
# SdkNotAvailableException on every Shopware version. None of our checks caught it: the unit suite
# does not install anything, `extension validate` does not read vendor/, and
# bin/ci-assert-zip-vendors-sdk.sh verifies the SDK is *in* the archive and that the autoloader
# *points* at it -- both of which were true while nothing loaded that autoloader.
#
# Above all it was invisible locally, because every sw-dev lane installs the SDK at project level
# as a Composer path repository. The class was always reachable by another route, so the bundled
# copy was never once exercised. The archive was only ever tested where the thing it ships was
# already present.
#
# Hence the refusal below: if the shop can still resolve Ucp\Sdk\... before we start, this script
# stops rather than reporting a pass. A green run that proves nothing is worse than no run.
#
# Usage:
#   bin/test-zip-install.sh --zip <file> --container <name> --shop-url <url> [--admin-user admin]
#                           [--admin-pass shopware] [--sync-session <mutagen session>]
#
# Example (sw-dev lane 6.5):
#   bin/test-zip-install.sh --zip ~/Downloads/SwagAgenticCommerce-1.3.99.zip \
#     --container shopware-6-5-branch-web-1 --shop-url http://sw65.localhost:8088 \
#     --sync-session agentic-commerce-65
#
set -euo pipefail

PLUGIN="SwagAgenticCommerce"
SDK_PROBE_CLASS='Ucp\Sdk\Symfony\UcpSdkBundle'

zip_file=""
container=""
shop_url=""
admin_user="admin"
admin_pass="shopware"
sync_session=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --zip) zip_file="$2"; shift 2 ;;
    --container) container="$2"; shift 2 ;;
    --shop-url) shop_url="${2%/}"; shift 2 ;;
    --admin-user) admin_user="$2"; shift 2 ;;
    --admin-pass) admin_pass="$2"; shift 2 ;;
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

in_shop() { docker exec -u www-data "${container}" sh -c "cd /var/www/html && $1"; }

say() { printf '  %s\n' "$1"; }

# Authenticate before temporarily removing a plugin that may already be active.
token=$(curl -sS -X POST "${shop_url}/api/oauth/token" -H 'Content-Type: application/json' \
  -d "{\"client_id\":\"administration\",\"grant_type\":\"password\",\"scopes\":\"write\",\"username\":\"${admin_user}\",\"password\":\"${admin_pass}\"}" \
  | python3 -c 'import json,sys; print(json.load(sys.stdin).get("access_token",""))')
[[ -n "${token}" ]] || { echo "Could not obtain an admin token from ${shop_url}." >&2; exit 1; }

# ---------------------------------------------------------------------------------------------
# Restore. Registered before anything is moved, so an interrupted run still puts the lane back.
# ---------------------------------------------------------------------------------------------
restored=0
composer_backed_up=0
registry_backed_up=0
plugin_moved=0
sdk_moved=0
# Invoked from the EXIT trap below, which shellcheck cannot see.
# shellcheck disable=SC2317,SC2329
restore() {
  if [[ "${restored}" -eq 1 ]]; then
    return 0
  fi
  restored=1
  echo "== restoring the lane"
  if [[ "${composer_backed_up}" -eq 1 ]]; then
    in_shop "cp /tmp/zit-composer.json composer.json && cp /tmp/zit-composer.lock composer.lock" || true
  fi
  if [[ "${registry_backed_up}" -eq 1 ]]; then
    in_shop "cp /tmp/zit-installed.json vendor/composer/installed.json && cp /tmp/zit-installed.php vendor/composer/installed.php" || true
  fi
  if [[ "${plugin_moved}" -eq 1 ]]; then
    in_shop "rm -rf custom/plugins/${PLUGIN} && mv /tmp/zit-plugin custom/plugins/${PLUGIN}" || true
  fi
  if [[ "${sdk_moved}" -eq 1 ]]; then
    in_shop "rm -rf vendor/ucp-php-sdk && mv /tmp/zit-sdk vendor/ucp-php-sdk" || true
  fi
  if [[ -n "${sync_session}" ]]; then
    mutagen sync resume "${sync_session}" >/dev/null 2>&1 || true
  fi
  say "lane restored; re-run your bootstrap if the plugin version looks off"
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
docker exec -i -u www-data "${container}" php <<'PHP'
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

in_shop "mv custom/plugins/${PLUGIN} /tmp/zit-plugin"
plugin_moved=1
if in_shop "test -d vendor/ucp-php-sdk"; then
  in_shop "mv vendor/ucp-php-sdk /tmp/zit-sdk"
  sdk_moved=1
fi
say "moved the plugin source and the project-level SDK aside"

# The refusal. If the shop still resolves the SDK, a successful install proves nothing about the
# archive -- which is exactly how the shipped defect stayed invisible.
probe=$(in_shop "php -r 'require \"vendor/autoload.php\"; echo class_exists(\"${SDK_PROBE_CLASS//\\/\\\\}\") ? \"yes\" : \"no\";'" 2>/dev/null || echo "error")
if [[ "${probe}" != "no" ]]; then
  echo "REFUSING TO RUN: the shop can still resolve ${SDK_PROBE_CLASS} (probe: ${probe})." >&2
  echo "  The archive's own vendored copy would never be exercised, so a pass would be" >&2
  echo "  meaningless. Remove the project-level SDK first." >&2
  exit 1
fi
say "confirmed: ${SDK_PROBE_CLASS} is NOT resolvable by the shop"

# ---------------------------------------------------------------------------------------------
echo "== installing the archive through the admin upload endpoint"



api() { curl -sS -o "$2" -w '%{http_code}' --max-time 300 -H "Authorization: Bearer ${token}" "${@:3}"; }

api POST /dev/null -X POST "${shop_url}/api/_action/extension/refresh" >/dev/null
# Some lanes answer 500 here on a PHP upload_tmp_dir quirk while still extracting the archive,
# so the upload status is reported rather than enforced; install is the check that matters.
upload=$(api POST /tmp/zit-upload.json -X POST "${shop_url}/api/_action/extension/upload" -F "file=@${zip_file};type=application/zip")
say "upload: HTTP ${upload}"
api POST /dev/null -X POST "${shop_url}/api/_action/extension/refresh" >/dev/null

install=$(api POST /tmp/zit-install.json -X POST "${shop_url}/api/_action/extension/install/plugin/${PLUGIN}")
if [[ "${install}" != "204" ]]; then
  echo "FAIL: install returned HTTP ${install}." >&2
  python3 -c '
import json
try:
    d = json.load(open("/tmp/zit-install.json"))
    print("      " + str(d["errors"][0].get("detail"))[:400])
except Exception:
    pass' >&2
  exit 1
fi
say "install: HTTP 204"

activate=$(api PUT /dev/null -X PUT "${shop_url}/api/_action/extension/activate/plugin/${PLUGIN}")
[[ "${activate}" == "204" ]] || { echo "FAIL: activate returned HTTP ${activate}." >&2; exit 1; }
say "activate: HTTP 204"

# The lane may already have this QA version marked active, so activation is a no-op.
# Drop routes cached while the source plugin was moved aside before probing the ZIP.
in_shop "php bin/console cache:clear --no-warmup" >/tmp/zit-cache-clear.log 2>&1

# ---------------------------------------------------------------------------------------------
echo "== checking it actually runs from the copy the archive shipped"

status=0

discovery=$(curl -sS -o /tmp/zit-profile.json -w '%{http_code}' --max-time 120 "${shop_url}/.well-known/ucp")
if [[ "${discovery}" == "200" ]]; then
  python3 -c '
import json
d = json.load(open("/tmp/zit-profile.json"))
u = d["ucp"]
print("  discovery: HTTP 200, version %s, %d capabilities" % (u["version"], len(u.get("capabilities", {}))))'
else
  echo "FAIL: /.well-known/ucp returned HTTP ${discovery}." >&2
  status=1
fi

bundle_bytes=$(curl -sS -o /dev/null -w '%{size_download}' --max-time 120 \
  "${shop_url}/bundles/swagagenticcommerce/administration/js/swag-agentic-commerce.js")
if [[ "${bundle_bytes}" -gt 50000 ]]; then
  say "admin bundle: ${bundle_bytes} bytes served without a build step"
else
  echo "FAIL: the administration bundle is ${bundle_bytes} bytes." >&2
  status=1
fi

# The point of the whole exercise: the SDK must come from the plugin's own vendor directory.
origin=$(in_shop "php -r '
require \"vendor/autoload.php\";
require \"custom/plugins/${PLUGIN}/vendor/autoload.php\";
echo (new ReflectionClass(\"${SDK_PROBE_CLASS//\\/\\\\}\"))->getFileName();
'" 2>/dev/null || echo "")
case "${origin}" in
  */custom/plugins/${PLUGIN}/vendor/*) say "SDK resolved from the archive: ${origin}" ;;
  "") echo "FAIL: could not resolve ${SDK_PROBE_CLASS} at all after install." >&2; status=1 ;;
  *) echo "FAIL: SDK resolved from ${origin}, not from the plugin's bundled vendor." >&2; status=1 ;;
esac

if [[ "${status}" -eq 0 ]]; then
  echo "== PASS: the archive installs and runs on a shop without the SDK"
fi

exit "${status}"
