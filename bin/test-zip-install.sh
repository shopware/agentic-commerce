#!/usr/bin/env bash
#
# Installs a built extension ZIP into a running Shopware the way a merchant does -- through the
# admin upload endpoint -- on a shop that does NOT already provide the UCP SDK.
#
# This exists because of a defect that shipped. 1.3.0 vendored the SDK into the plugin's own
# vendor/, Shopware never loads that autoloader, and `plugin:install` therefore died with
# SdkNotAvailableException on every Shopware version. None of our checks caught it: the unit suite
# does not install anything, and `extension validate` does not read vendor/.
#
# The archive now ships no dependencies at all and lets Shopware's own `composer require` install
# them, so what this asserts is that the install brought the SDK into the shop -- not that the
# plugin found a copy it carried.
#
# It then takes the SDK away again from the installed, active extension. That is the state an
# update leaves behind for one request, and 1.3.0 answered 500 on every page in it, storefront
# included. Anything wired to the SDK outside the SdkAvailability guard -- a service, a route, a
# bundle, a class the service glob reflects on -- stops the container compiling and fails there.
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

# Print curl's status code, or 000 when there is not one. Keeps the code when curl prints it and
# then exits non-zero anyway, which is how "404000" happened.
http_status() {
  local status
  status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time "${2:-120}" "$1" 2>/dev/null || true)"

  if [[ ! "${status}" =~ ^[0-9]{3}$ ]]; then
    status="000"
  fi

  printf '%s' "${status}"
}

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
sdk_hidden=0
archive_present=0
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
  if [[ "${sdk_hidden}" -eq 1 ]]; then
    in_shop "rm -rf vendor/ucp-php-sdk && mv /tmp/zit-sdk-hidden vendor/ucp-php-sdk" || true
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

# A development lane has the plugin source checked out here; a CI lane boots without it
# (ci-smoke.sh with CI_SMOKE_SKIP_PLUGIN=1 removes it). Either way what must not be present when
# the archive is uploaded is a second copy of the plugin.
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
# Set before the upload, not after a successful install: the upload extracts the archive into
# custom/plugins/ and refreshes the plugin record, so from here on the lane holds a copy whether
# or not anything below succeeds -- and an install that does not is the failure this exists for.
archive_present=1
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

# The point of the whole exercise: installing the archive must be what brings the SDK into the
# shop. executeComposerCommands() makes Shopware run `composer require` against the project, and
# `custom/plugins/*` is a path repository in shopware/production, so the plugin resolves from the
# extracted directory and its pinned SDK comes from Packagist -- into the project's vendor, on the
# one autoloader the shop already has. The refusal above proved none of it was there beforehand.
probe_script=$(cat <<'PHP'
<?php
chdir('/var/www/html');
$plugin = 'custom/plugins/'.getenv('ZIT_PLUGIN');
require 'vendor/autoload.php';
$class = getenv('ZIT_CLASS');
$origin = class_exists($class) ? (new ReflectionClass($class))->getFileName() : 'unresolved';
printf(
    "%s|%s|%s\n",
    $origin,
    is_dir($plugin.'/vendor') ? 'plugin-vendor-present' : 'no-plugin-vendor',
    file_exists('vendor/shopware/agentic-commerce') ? 'required-by-composer' : 'not-required'
);
PHP
)

probe=$(printf '%s' "${probe_script}" | docker exec -i -u www-data \
  -e "ZIT_PLUGIN=${PLUGIN}" -e "ZIT_CLASS=${SDK_PROBE_CLASS}" "${container}" php 2>/dev/null \
  || echo "error|error|error")

IFS='|' read -r sdk_origin plugin_vendor composer_required <<< "${probe}"

case "${sdk_origin}" in
  */vendor/ucp-php-sdk/*) say "SDK resolved from the shop's own vendor: ${sdk_origin}" ;;
  */custom/plugins/${PLUGIN}/*)
    echo "FAIL: the SDK resolved from inside the plugin (${sdk_origin})." >&2
    echo "      The archive is supposed to ship none, and Composer to install it." >&2
    status=1
    ;;
  *)
    echo "FAIL: ${SDK_PROBE_CLASS} is not resolvable after install (${sdk_origin})." >&2
    status=1
    ;;
esac

if [[ "${plugin_vendor}" != "no-plugin-vendor" ]]; then
  echo "FAIL: the installed plugin has a vendor/ directory." >&2
  echo "      Its autoloader would register a second Composer ClassLoader in the shop." >&2
  status=1
else
  say "the installed plugin carries no vendor/ of its own"
fi

if [[ "${composer_required}" != "required-by-composer" ]]; then
  echo "FAIL: vendor/shopware/agentic-commerce is missing, so the install never ran composer." >&2
  echo "      Something resolved the SDK by another route and this run proves nothing." >&2
  status=1
else
  say "Shopware required the plugin through Composer"
fi

datasets=$(in_shop "php -r 'require \"vendor/autoload.php\"; echo count(Composer\\InstalledVersions::getAllRawData());'" 2>/dev/null || echo "error")
if [[ "${datasets}" == "1" ]]; then
  say "Composer reports a single registered autoloader dataset"
else
  echo "FAIL: Composer reports ${datasets} autoloader datasets, expected 1." >&2
  status=1
fi

# ---------------------------------------------------------------------------------------------
echo "== checking the shop survives its SDK going missing"

# The regression this exists for: an update extracts the new files one request before Shopware
# runs composer, so an active extension boots with its dependency missing. 1.3.0 answered 500 on
# every page in that state, storefront included, until someone installed the SDK by hand.
#
# Taking the SDK away from an installed, active extension reproduces exactly that, and it is what
# catches SDK-dependent wiring added outside the SdkAvailability guard -- a service, a route, a
# bundle, or a class implementing an SDK interface that the service glob then reflects on. Any of
# those stop the container compiling, and the shop goes down with it.
in_shop "cp vendor/composer/installed.json /tmp/zit-sdk-registry.json && cp vendor/composer/installed.php /tmp/zit-sdk-registry.php"
in_shop "mv vendor/ucp-php-sdk /tmp/zit-sdk-hidden"
sdk_hidden=1

docker exec -i -u www-data "${container}" php <<'PHP' >/dev/null
<?php
chdir('/var/www/html');
$names = ['ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'];
$installed = json_decode(file_get_contents('vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$installed['packages'] = array_values(array_filter(
    $installed['packages'],
    static fn (array $package): bool => !in_array($package['name'], $names, true),
));
file_put_contents('vendor/composer/installed.json', json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$versions = require 'vendor/composer/installed.php';
foreach ($names as $name) {
    unset($versions['versions'][$name]);
}
file_put_contents('vendor/composer/installed.php', '<?php return '.var_export($versions, true).';');
PHP

in_shop "rm -rf var/cache/* var/log/swag-agentic-commerce.log"

storefront="$(http_status "${shop_url}/" 180)"
if [[ "${storefront}" == "200" ]]; then
  say "storefront answers 200 with the extension installed and the SDK gone"
else
  echo "FAIL: the storefront answered HTTP ${storefront} without the SDK." >&2
  echo "      An active extension must not take the shop down while composer has not run yet;" >&2
  echo "      something is wired to the SDK outside the SdkAvailability guard." >&2
  status=1
fi

degraded="$(http_status "${shop_url}/.well-known/ucp")"
case "${degraded}" in
  404) say "UCP is switched off rather than failing (HTTP 404)" ;;
  500)
    echo "FAIL: /.well-known/ucp answered 500 without the SDK; the routes are still registered." >&2
    status=1
    ;;
  *) echo "FAIL: /.well-known/ucp answered HTTP ${degraded}, expected 404 while degraded." >&2; status=1 ;;
esac

if in_shop "grep -q 'composer require ucp-php-sdk' var/log/swag-agentic-commerce.log" 2>/dev/null; then
  say "var/log/swag-agentic-commerce.log names the command that fixes it"
else
  echo "FAIL: nothing in var/log/swag-agentic-commerce.log tells the merchant how to recover." >&2
  status=1
fi

# Put it back, so the lane is left as it was found. Recovery is deliberately not asserted here:
# the install above already proves the same transition -- a shop with no SDK ends with UCP
# answering -- and doing it a second time by hand only measures when a PHP worker lets go of the
# container it already built, which is the platform's business and not this extension's.
in_shop "rm -rf vendor/ucp-php-sdk && mv /tmp/zit-sdk-hidden vendor/ucp-php-sdk"
in_shop "cp /tmp/zit-sdk-registry.json vendor/composer/installed.json && cp /tmp/zit-sdk-registry.php vendor/composer/installed.php"
sdk_hidden=0
in_shop "rm -rf var/cache/*"

if [[ "${status}" -eq 0 ]]; then
  echo "== PASS: the archive installs, runs, and the shop survives losing the SDK"
fi

exit "${status}"
