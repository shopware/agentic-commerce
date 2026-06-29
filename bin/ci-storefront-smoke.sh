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

if [[ ! -d "${PLUGIN_ROOT}/node_modules/@playwright/test" ]]; then
  echo "Playwright dependencies are missing. Run 'npm install' in ${PLUGIN_ROOT} before storefront browser validation." >&2
  exit 1
fi

(
  cd "${PLUGIN_ROOT}"
  BASE_URL="${BASE_URL}" \
    SHOPWARE_REF="${branch_name}" \
    PLAYWRIGHT_OUTPUT_DIR="${PLUGIN_ROOT}/var/playwright-results/${branch_name}/storefront" \
    npm run test:e2e:storefront -- --reporter=list
)

echo "Storefront build and UI smoke passed for ${SHOPWARE_DIR} (${branch_name:-unknown})."
