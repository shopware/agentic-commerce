#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-storefront-smoke.sh <shopware-dir>" >&2
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "Required dependency 'curl' is not available." >&2
  exit 1
fi

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHOPWARE_DIR="$(cd "$1" && pwd)"
BOOTSTRAP_MODE="${CI_STOREFRONT_BOOTSTRAP_MODE:-warm}"
KEEP_STACK="${CI_STOREFRONT_KEEP_STACK:-1}"

if [[ ! -d "${SHOPWARE_DIR}" ]]; then
  echo "Shopware checkout not found at ${SHOPWARE_DIR}." >&2
  exit 1
fi

# Shared container/lane helpers (web, detect_base_url, lane_detect_compose_cmd).
# shellcheck source=bin/lib/lane.sh
source "${PLUGIN_ROOT}/bin/lib/lane.sh"

read -ra compose_cmd <<< "$(lane_detect_compose_cmd)"

if [[ ! -f "${SHOPWARE_DIR}/compose.yaml" ]]; then
  echo "Missing ${SHOPWARE_DIR}/compose.yaml. In CI, run bin/ci-write-compose.sh before bin/ci-storefront-smoke.sh." >&2
  exit 1
fi

compose_files=("${SHOPWARE_DIR}/compose.yaml")
if [[ -f "${SHOPWARE_DIR}/compose.override.yaml" ]]; then
  compose_files+=("${SHOPWARE_DIR}/compose.override.yaml")
fi

BASE_URL="${BASE_URL:-$(detect_base_url)}"

compose=("${compose_cmd[@]}")
for compose_file in "${compose_files[@]}"; do
  compose+=(-f "${compose_file}")
done

# web() comes from bin/lib/lane.sh.
storefront_sh() {
  local command="$1"

  if [[ "${branch_name:-}" == "6.5.x" ]]; then
    # Shopware 6.5 pins an older Node engine in the storefront toolchain and
    # still pulls Puppeteer as part of the storefront dependency graph.
    # Local arm64 containers do not provide the Chromium binary Puppeteer wants
    # to download, but the storefront production build does not need it.
    web sh -lc "export npm_config_engine_strict=false PUPPETEER_SKIP_DOWNLOAD=1 PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=1; ${command}"
    return
  fi

  web sh -lc "${command}"
}

install_storefront_dependencies() {
  if [[ "${branch_name:-}" == "6.5.x" ]]; then
    storefront_sh 'cd /var/www/html/src/Storefront/Resources/app/storefront && export PATH="$PWD/node_modules/.bin:$PATH" && npm install --no-audit --prefer-offline'
    return
  fi

  storefront_sh 'cd /var/www/html && composer init:js'
}

branch_name="${SHOPWARE_REF:-$(git -C "${SHOPWARE_DIR}" rev-parse --abbrev-ref HEAD 2>/dev/null || true)}"

if [[ -z "${branch_name}" || "${branch_name}" == "HEAD" ]]; then
  if [[ -f "${SHOPWARE_DIR}/src/Administration/Resources/app/administration/build.ts" ]]; then
    branch_name="trunk"
  elif grep -q 'ADMIN_VITE' "${SHOPWARE_DIR}/src/Administration/Resources/app/administration/package.json" 2>/dev/null; then
    branch_name="6.6.x"
  else
    branch_name="6.5.x"
  fi
fi

CI_SMOKE_MODE="${BOOTSTRAP_MODE}" \
CI_SMOKE_KEEP_STACK=1 \
CI_SMOKE_BOOTSTRAP_ONLY=1 \
"${PLUGIN_ROOT}/bin/ci-smoke.sh" "${SHOPWARE_DIR}"

cleanup() {
  if [[ "${KEEP_STACK}" == "0" ]]; then
    "${compose[@]}" down -v >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

storefront_sh 'cd /var/www/html && if [ ! -x src/Storefront/Resources/app/storefront/node_modules/.bin/webpack ]; then exit 10; fi' \
  || install_storefront_dependencies

# Fresh 6.5/6.6 installs can miss the default Storefront theme assignment for
# their sales channels. Ensure it exists before rebuilding storefront assets.
storefront_sh 'cd /var/www/html && bin/console theme:refresh >/dev/null 2>&1 || true && bin/console theme:change --all Storefront --no-compile >/dev/null 2>&1 || true'

# The storefront build copies selected node modules into
# src/Storefront/Resources/app/storefront/vendor. On local warm lanes this can
# contain stale files from older builds, which breaks trunk's copy-to-vendor
# step with "Cannot overwrite non-directory ...". Clear the generated vendor
# mirror before rebuilding so the smoke stays idempotent.
storefront_sh 'cd /var/www/html && rm -rf src/Storefront/Resources/app/storefront/vendor && mkdir -p src/Storefront/Resources/app/storefront/vendor'
storefront_sh 'cd /var/www/html && composer build:js:storefront'
web php /var/www/html/bin/console theme:compile >/dev/null
web php /var/www/html/bin/console assets:install >/dev/null

storefront_html="$(curl -fsS "${BASE_URL}/")"
theme_css_path="$(grep -o -m1 -E 'href="[^"]*/theme/[^"]+/css/[^"]+\.css[^"]*"' <<<"${storefront_html}" | head -n1 | sed -E 's/^href="//; s/"$//' || true)"
if [[ -z "${theme_css_path}" ]]; then
  echo "The storefront homepage links no compiled theme stylesheet." >&2
  exit 1
fi
theme_css_url="${theme_css_path}"
if [[ "${theme_css_path}" == /* ]]; then
  theme_css_url="${BASE_URL}${theme_css_path}"
fi
if ! curl -fsS -o /dev/null "${theme_css_url}"; then
  echo "The compiled theme stylesheet ${theme_css_url} is not served." >&2
  exit 1
fi

echo "Storefront build and theme smoke passed for ${SHOPWARE_DIR} (${branch_name:-unknown})."
