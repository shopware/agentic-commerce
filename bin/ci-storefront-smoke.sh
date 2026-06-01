#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-storefront-smoke.sh <shopware-dir>" >&2
  exit 1
fi

for dependency in curl; do
  if ! command -v "${dependency}" >/dev/null 2>&1; then
    echo "Required dependency '${dependency}' is not available." >&2
    exit 1
  fi
done

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHOPWARE_DIR="$(cd "$1" && pwd)"
BOOTSTRAP_MODE="${CI_STOREFRONT_BOOTSTRAP_MODE:-warm}"
KEEP_STACK="${CI_STOREFRONT_KEEP_STACK:-1}"

if [[ ! -d "${SHOPWARE_DIR}" ]]; then
  echo "Shopware checkout not found at ${SHOPWARE_DIR}." >&2
  exit 1
fi

if command -v docker >/dev/null 2>&1; then
  compose_cmd=(docker compose)
elif command -v podman >/dev/null 2>&1; then
  compose_cmd=(podman compose)
else
  echo "Neither docker nor podman is available." >&2
  exit 1
fi

compose_files=("${SHOPWARE_DIR}/compose.yaml")
if [[ -f "${SHOPWARE_DIR}/compose.override.yaml" ]]; then
  compose_files+=("${SHOPWARE_DIR}/compose.override.yaml")
fi

detect_base_url() {
  local detected
  detected="$(sed -nE 's/^[[:space:]]*APP_URL:[[:space:]]*(.+)$/\1/p' "${SHOPWARE_DIR}/compose.yaml" | head -n 1)"
  if [[ -n "${detected}" ]]; then
    printf '%s\n' "${detected}"
    return 0
  fi

  printf 'http://localhost:8000\n'
}

BASE_URL="${BASE_URL:-$(detect_base_url)}"

compose=("${compose_cmd[@]}")
for compose_file in "${compose_files[@]}"; do
  compose+=(-f "${compose_file}")
done

web() {
  "${compose[@]}" exec -T web "$@"
}

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

home_html="$(curl -fsS "${BASE_URL}/")"
if ! grep -q '/theme/' <<<"${home_html}"; then
  if ! grep -q 'themeAssetsPublicPath' <<<"${home_html}"; then
    echo "Storefront home page did not expose the expected theme asset bootstrap." >&2
    exit 1
  fi

  theme_assets_url="$(sed -nE "s/.*themeAssetsPublicPath = '([^']+)'.*/\\1/p" <<<"${home_html}" | head -n 1)"
  theme_js_url="$(sed -nE "s/.*themeJsPublicPath = '([^']+)'.*/\\1/p" <<<"${home_html}" | head -n 1)"

  bootstrap_ok=0
  if [[ -n "${theme_assets_url}" ]] && curl -fsSI "${theme_assets_url}" >/dev/null 2>&1; then
    bootstrap_ok=1
  fi

  if [[ "${bootstrap_ok}" -eq 0 && -n "${theme_js_url}" ]] && curl -fsSI "${theme_js_url}" >/dev/null 2>&1; then
    bootstrap_ok=1
  fi

  if [[ "${bootstrap_ok}" -eq 0 ]]; then
    echo "Storefront home page exposed only unresolved theme asset bootstrap paths." >&2
    exit 1
  fi
fi

if ! grep -q 'class="header-main"' <<<"${home_html}" || ! grep -q 'is-ctl-navigation' <<<"${home_html}"; then
  echo "Storefront home page did not render the expected shell." >&2
  exit 1
fi

cart_html="$(curl -fsS "${BASE_URL}/checkout/cart")"
if ! grep -q 'Shopping cart' <<<"${cart_html}" && ! grep -q 'shopping cart' <<<"${cart_html}"; then
  echo "Storefront cart page did not render the expected cart shell." >&2
  exit 1
fi

echo "Storefront build and UI smoke passed for ${SHOPWARE_DIR} (${branch_name:-unknown})."
