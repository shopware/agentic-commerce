#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: bin/ci-admin-smoke.sh <shopware-dir> [auto|webpack|vite]" >&2
  exit 1
fi

for dependency in jq; do
  if ! command -v "${dependency}" >/dev/null 2>&1; then
    echo "Required dependency '${dependency}' is not available." >&2
    exit 1
  fi
done

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHOPWARE_DIR="$(cd "$1" && pwd)"
REQUESTED_MODE="${2:-auto}"
BOOTSTRAP_MODE="${CI_ADMIN_BOOTSTRAP_MODE:-warm}"
KEEP_STACK="${CI_ADMIN_KEEP_STACK:-1}"

case "${REQUESTED_MODE}" in
  auto|webpack|vite)
    ;;
  *)
    echo "Unsupported admin build mode '${REQUESTED_MODE}'. Use auto, webpack, or vite." >&2
    exit 1
    ;;
esac

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

if [[ ! -f "${SHOPWARE_DIR}/compose.yaml" ]]; then
  echo "Missing ${SHOPWARE_DIR}/compose.yaml. In CI, run bin/ci-write-compose.sh before bin/ci-admin-smoke.sh." >&2
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

detect_shopware_lane() {
  if [[ "${SHOPWARE_REF:-}" == "6.5.x" || "${SHOPWARE_REF:-}" == "6.6.x" || "${SHOPWARE_REF:-}" == "trunk" ]]; then
    printf '%s\n' "${SHOPWARE_REF}"
    return 0
  fi

  if [[ -f "${SHOPWARE_DIR}/src/Administration/Resources/app/administration/build.ts" ]]; then
    printf 'trunk\n'
    return 0
  fi

  if grep -q 'ADMIN_VITE' "${SHOPWARE_DIR}/src/Administration/Resources/app/administration/package.json" 2>/dev/null; then
    printf '6.6.x\n'
    return 0
  fi

  printf '6.5.x\n'
}

BASE_URL="${BASE_URL:-$(detect_base_url)}"

compose=("${compose_cmd[@]}")
for compose_file in "${compose_files[@]}"; do
  compose+=(-f "${compose_file}")
done

web() {
  "${compose[@]}" exec -T web "$@"
}

admin_sh() {
  local command="$1"

  if [[ "${branch_name:-}" == "6.5.x" ]]; then
    # Shopware 6.5 pins an older Node engine in .npmrc/package.json, but our
    # local dev image currently ships a newer runtime. Relax engine-strict for
    # lane validation until a dedicated 6.5-compatible image is available.
    web sh -lc "export npm_config_engine_strict=false; ${command}"
    return
  fi

  web sh -lc "${command}"
}

install_admin_dependencies() {
  if [[ "${branch_name:-}" == "6.5.x" ]]; then
    admin_sh 'cd /var/www/html/src/Administration/Resources/app/administration && export PATH="$PWD/node_modules/.bin:$PATH" && export PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=1 && export PUPPETEER_SKIP_DOWNLOAD=1 && npm install --no-audit --prefer-offline'
    return
  fi

  admin_sh 'cd /var/www/html && composer init:js'
}

branch_name="$(detect_shopware_lane)"

case "${branch_name}" in
  6.5.x)
    if [[ "${REQUESTED_MODE}" == "vite" ]]; then
      echo "Vite administration builds are not supported on Shopware 6.5.x." >&2
      exit 1
    fi
    resolved_mode="webpack"
    ;;
  trunk)
    if [[ "${REQUESTED_MODE}" == "webpack" ]]; then
      echo "Webpack administration builds are not supported on Shopware trunk." >&2
      exit 1
    fi
    resolved_mode="vite"
    ;;
  6.6.x)
    resolved_mode="${REQUESTED_MODE}"
    ;;
  *)
    resolved_mode="${REQUESTED_MODE}"
    ;;
esac

if [[ "${resolved_mode}" == "auto" ]]; then
  resolved_mode="auto"
fi

admin_vite_env=0
if [[ "${resolved_mode}" == "vite" ]]; then
  admin_vite_env=1
fi

ADMIN_VITE="${admin_vite_env}" \
CI_SMOKE_MODE="${BOOTSTRAP_MODE}" \
CI_SMOKE_KEEP_STACK=1 \
CI_SMOKE_BOOTSTRAP_ONLY=1 \
"${PLUGIN_ROOT}/bin/ci-smoke.sh" "${SHOPWARE_DIR}"

cleanup() {
  if [[ "${branch_name}" == "6.6.x" && -n "${feature_backup_mode:-}" ]]; then
    if [[ "${feature_file_preexisted:-0}" == "1" ]]; then
      web sh -lc 'mv /var/www/html/var/config_js_features.json.swag-agentic-commerce.bak /var/www/html/var/config_js_features.json'
    else
      web sh -lc 'rm -f /var/www/html/var/config_js_features.json /var/www/html/var/config_js_features.json.swag-agentic-commerce.bak'
    fi
  fi

  if [[ "${KEEP_STACK}" == "0" ]]; then
    "${compose[@]}" down -v >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

admin_sh 'cd /var/www/html && if [ ! -x src/Administration/Resources/app/administration/node_modules/.bin/ts-node ]; then exit 10; fi' \
  || install_admin_dependencies
web sh -lc 'cd /var/www/html && php bin/console bundle:dump && php bin/console feature:dump'
web sh -lc 'cd /var/www/html && jq -e ".SwagAgenticCommerce.administration.entryFilePath == \"Resources/app/administration/src/main.js\"" var/plugins.json >/dev/null' \
  || {
    echo "SwagAgenticCommerce is missing from var/plugins.json after bundle:dump. Check filesystem sync/stale var/plugins.json before building administration." >&2
    exit 1
  }

if [[ "${branch_name}" == "6.6.x" && "${resolved_mode}" != "auto" ]]; then
  feature_file_preexisted="$(web sh -lc 'if [ -f /var/www/html/var/config_js_features.json ]; then echo 1; else echo 0; fi')"
  feature_backup_mode="${resolved_mode}"

  if [[ "${feature_file_preexisted}" == "1" ]]; then
    web sh -lc 'cp /var/www/html/var/config_js_features.json /var/www/html/var/config_js_features.json.swag-agentic-commerce.bak'
  fi

  if [[ "${resolved_mode}" == "vite" ]]; then
    web sh -lc 'tmp_file="$(mktemp)"; if [ -f /var/www/html/var/config_js_features.json ]; then jq ".ADMIN_VITE = true" /var/www/html/var/config_js_features.json > "${tmp_file}"; else jq -n "{ADMIN_VITE: true}" > "${tmp_file}"; fi; mv "${tmp_file}" /var/www/html/var/config_js_features.json'
  else
    web sh -lc 'tmp_file="$(mktemp)"; if [ -f /var/www/html/var/config_js_features.json ]; then jq ".ADMIN_VITE = false" /var/www/html/var/config_js_features.json > "${tmp_file}"; else jq -n "{ADMIN_VITE: false}" > "${tmp_file}"; fi; mv "${tmp_file}" /var/www/html/var/config_js_features.json'
  fi
fi

admin_sh 'cd /var/www/html && composer admin:generate-entity-schema-types'

web sh -lc 'rm -rf /var/www/html/custom/plugins/SwagAgenticCommerce/src/Resources/public/administration /var/www/html/custom/plugins/SwagAgenticCommerce/src/Resources/public/static /var/www/html/public/bundles/swagagenticcommerce/administration /var/www/html/public/bundles/swagagenticcommerce/static'

if [[ "${branch_name}" == "6.6.x" && "${resolved_mode}" == "vite" ]]; then
  web sh -lc 'cd /var/www/html/src/Administration/Resources/app/administration && export PROJECT_ROOT=/var/www/html && export VITE_MODE=production && export PATH="$PWD/node_modules/.bin:$PATH" && /var/www/html/bin/exec-with-env npm run vite build && ts-node -T build/plugins.vite.ts'
else
  admin_sh 'cd /var/www/html && composer npm:admin run build'
fi

web php /var/www/html/bin/console assets:install

if [[ "${resolved_mode}" == "webpack" ]]; then
  # A previous Vite/dev run can leave this file behind. On 6.6 with ADMIN_VITE
  # disabled the admin can still pick it up and try loading plugins from a
  # non-running dev server, so the built static plugin bundle never registers.
  web sh -lc 'rm -f /var/www/html/public/bundles/administration/administration/sw-plugin-dev.json'
fi

bundle_artifact="$(web sh -lc 'find /var/www/html/public -path "*swagagenticcommerce*" -type f | head -n 1')"

if [[ -z "${bundle_artifact}" ]]; then
  echo "Unable to locate a built SwagAgenticCommerce administration asset under /var/www/html/public." >&2
  exit 1
fi

admin_html="$(curl -fsS "${BASE_URL}/admin")"
if ! grep -q 'Log in to Shopware' <<<"${admin_html}" && ! grep -q 'Administration' <<<"${admin_html}"; then
  echo "Administration UI shell did not render after the build." >&2
  exit 1
fi

if [[ "${CI_ADMIN_BROWSER_VALIDATE:-0}" == "1" ]]; then
  if [[ ! -d "${PLUGIN_ROOT}/node_modules/@playwright/test" ]]; then
    echo "Playwright dependencies are missing. Run 'npm install' in ${PLUGIN_ROOT} before enabling CI_ADMIN_BROWSER_VALIDATE=1." >&2
    exit 1
  fi

  UCP_ADMIN_SCREENSHOT_DIR="${PLUGIN_ROOT}/var/qa/admin-screenshots/${branch_name}/${resolved_mode}" \
    node "${PLUGIN_ROOT}/bin/validate-ucp-admin-browser.mjs" \
      --base-url "${BASE_URL}" \
      --lane "${branch_name}-${resolved_mode}"
fi

echo "Administration build and login-shell smoke passed for ${SHOPWARE_DIR} (${branch_name:-unknown}, ${resolved_mode})."
