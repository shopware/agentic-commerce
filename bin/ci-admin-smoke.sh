#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: bin/ci-admin-smoke.sh <shopware-dir> [auto|webpack|vite]" >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "Required dependency 'jq' is not available." >&2
  exit 1
fi

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHOPWARE_DIR="$(cd "$1" && pwd)"
REQUESTED_MODE="${2:-auto}"
BOOTSTRAP_MODE="${CI_ADMIN_BOOTSTRAP_MODE:-warm}"
KEEP_STACK="${CI_ADMIN_KEEP_STACK:-1}"
CORE_ONLY="${CI_ADMIN_CORE_ONLY:-0}"

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

if [[ "${CORE_ONLY}" != "0" && "${CORE_ONLY}" != "1" ]]; then
  echo "Unsupported CI_ADMIN_CORE_ONLY '${CORE_ONLY}'. Use '0' or '1'." >&2
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

web_container_id() {
  "${compose[@]}" ps -q web
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

  admin_sh 'cd /var/www/html && composer npm:admin clean-install --no-audit --prefer-offline'
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
CI_SMOKE_SKIP_PLUGIN="${CORE_ONLY}" \
"${PLUGIN_ROOT}/bin/ci-smoke.sh" "${SHOPWARE_DIR}"

cleanup() {
  if [[ "${branch_name}" == "6.6.x" && -n "${feature_backup_mode:-}" ]]; then
    if [[ "${feature_file_preexisted:-0}" == "1" ]]; then
      web sh -lc 'mv /var/www/html/var/config_js_features.json.swag-agentic-commerce.bak /var/www/html/var/config_js_features.json' || true
    else
      web sh -lc 'rm -f /var/www/html/var/config_js_features.json /var/www/html/var/config_js_features.json.swag-agentic-commerce.bak' || true
    fi
  fi

  if [[ "${KEEP_STACK}" == "0" ]]; then
    "${compose[@]}" down -v >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

admin_sh 'cd /var/www/html && if [ ! -x src/Administration/Resources/app/administration/node_modules/.bin/ts-node ]; then exit 10; fi' \
  || install_admin_dependencies
web sh -lc 'cd /var/www/html && php bin/console bundle:dump'
web sh -lc 'cd /var/www/html && php bin/console feature:dump' || {
  if [[ "${CI:-}" != "true" && "${BOOTSTRAP_MODE}" != "ci" ]] && web sh -lc 'test -f /var/www/html/var/config_js_features.json'; then
    echo "Unable to rewrite var/config_js_features.json; continuing with the existing local feature dump." >&2
  else
    exit 1
  fi
}
if [[ "${CORE_ONLY}" == "1" ]]; then
  web sh -lc 'cd /var/www/html && jq -e "has(\"SwagAgenticCommerce\") | not" var/plugins.json >/dev/null' \
    || {
      echo "SwagAgenticCommerce must not be present while building the core administration shell for zip-install smoke." >&2
      exit 1
    }
else
  web sh -lc 'cd /var/www/html && jq -e ".SwagAgenticCommerce.administration.entryFilePath == \"Resources/app/administration/src/main.js\"" var/plugins.json >/dev/null' \
    || {
      echo "SwagAgenticCommerce is missing from var/plugins.json after bundle:dump. Check filesystem sync/stale var/plugins.json before building administration." >&2
      exit 1
    }
fi

if [[ "${branch_name}" == "6.6.x" && "${resolved_mode}" != "auto" ]]; then
  feature_file_preexisted="$(web sh -lc 'if [ -f /var/www/html/var/config_js_features.json ]; then echo 1; else echo 0; fi')"
  feature_backup_mode="${resolved_mode}"

  if [[ "${feature_file_preexisted}" == "1" ]]; then
    web sh -lc 'cp /var/www/html/var/config_js_features.json /var/www/html/var/config_js_features.json.swag-agentic-commerce.bak' || {
      if [[ "${CI:-}" != "true" && "${BOOTSTRAP_MODE}" != "ci" ]]; then
        echo "Unable to back up var/config_js_features.json in local 6.6.x; continuing without restore backup." >&2
        feature_file_preexisted="0"
      else
        exit 1
      fi
    }
  fi

  if [[ "${resolved_mode}" == "vite" ]]; then
    web sh -lc 'tmp_file="$(mktemp)"; if [ -f /var/www/html/var/config_js_features.json ]; then jq ".ADMIN_VITE = true | .\"admin.vite\" = true" /var/www/html/var/config_js_features.json > "${tmp_file}"; else jq -n "{ADMIN_VITE: true, \"admin.vite\": true}" > "${tmp_file}"; fi; mv "${tmp_file}" /var/www/html/var/config_js_features.json' || {
      if [[ "${CI:-}" != "true" && "${BOOTSTRAP_MODE}" != "ci" ]]; then
        echo "Unable to rewrite var/config_js_features.json for local 6.6.x Vite compile-only mode; continuing." >&2
      else
        exit 1
      fi
    }
  else
    web sh -lc 'tmp_file="$(mktemp)"; if [ -f /var/www/html/var/config_js_features.json ]; then jq ".ADMIN_VITE = false | .\"admin.vite\" = false" /var/www/html/var/config_js_features.json > "${tmp_file}"; else jq -n "{ADMIN_VITE: false, \"admin.vite\": false}" > "${tmp_file}"; fi; mv "${tmp_file}" /var/www/html/var/config_js_features.json' || {
      if [[ "${CI:-}" != "true" && "${BOOTSTRAP_MODE}" != "ci" ]]; then
        echo "Unable to rewrite var/config_js_features.json for local 6.6.x webpack mode; continuing with the existing feature dump." >&2
      else
        exit 1
      fi
    }
  fi
fi

admin_sh 'cd /var/www/html && composer admin:generate-entity-schema-types'

if [[ "${CORE_ONLY}" == "1" ]]; then
  web sh -lc 'rm -rf /var/www/html/public/bundles/administration /var/www/html/src/Administration/Resources/public/administration'
else
  web sh -lc 'rm -rf /var/www/html/custom/plugins/SwagAgenticCommerce/src/Resources/public/administration /var/www/html/custom/plugins/SwagAgenticCommerce/src/Resources/public/static /var/www/html/public/bundles/swagagenticcommerce/administration /var/www/html/public/bundles/swagagenticcommerce/static'
fi

if [[ "${CORE_ONLY}" == "1" && "${resolved_mode}" == "vite" ]]; then
  web sh -lc 'cd /var/www/html/src/Administration/Resources/app/administration && export PROJECT_ROOT=/var/www/html && export VITE_MODE=production && export PATH="$PWD/node_modules/.bin:$PATH" && /var/www/html/bin/exec-with-env npm run build'
elif [[ "${branch_name}" == "6.6.x" && "${resolved_mode}" == "vite" ]]; then
  web sh -lc 'cd /var/www/html/src/Administration/Resources/app/administration && export PROJECT_ROOT=/var/www/html && export VITE_MODE=production && export PATH="$PWD/node_modules/.bin:$PATH" && /var/www/html/bin/exec-with-env npm run vite build && ts-node -T build/plugins.vite.ts'
else
  admin_sh 'cd /var/www/html && composer npm:admin run build'
fi

web php /var/www/html/bin/console assets:install

# A previous Vite/dev run can leave this file behind. Production admin
# validation must use the built static plugin bundle, not a non-running dev
# server entry from sw-plugin-dev.json.
web sh -lc 'rm -f /var/www/html/public/bundles/administration/administration/sw-plugin-dev.json'

if [[ "${CORE_ONLY}" == "1" ]]; then
  bundle_artifact="$(web sh -lc 'find /var/www/html/public/bundles/administration -type f | head -n 1')"
else
  bundle_artifact="$(web sh -lc 'find /var/www/html/public -path "*swagagenticcommerce*" -type f | head -n 1')"
fi

if [[ -z "${bundle_artifact}" ]]; then
  if [[ "${CORE_ONLY}" == "1" ]]; then
    echo "Unable to locate a built core administration asset under /var/www/html/public/bundles/administration." >&2
  else
    echo "Unable to locate a built SwagAgenticCommerce administration asset under /var/www/html/public." >&2
  fi
  exit 1
fi

admin_html="$(curl -fsS "${BASE_URL}/admin")"
if ! grep -q 'Log in to Shopware' <<<"${admin_html}" && ! grep -q 'Administration' <<<"${admin_html}"; then
  echo "Administration UI shell did not render after the build." >&2
  exit 1
fi

if [[ -n "${CI_ADMIN_EXPORT_PLUGIN_PUBLIC:-}" ]]; then
  web_id="$(web_container_id)"
  if [[ -z "${web_id}" ]]; then
    echo "Unable to resolve the web container id for ${SHOPWARE_DIR}." >&2
    exit 1
  fi

  export_dir="${CI_ADMIN_EXPORT_PLUGIN_PUBLIC}"
  rm -rf "${export_dir}"
  mkdir -p "${export_dir}"
  "${compose_cmd[0]}" cp "${web_id}:/var/www/html/custom/plugins/SwagAgenticCommerce/src/Resources/public/." "${export_dir}/"
  chmod -R a+rX "${export_dir}"
fi

if [[ "${CI_ADMIN_BROWSER_VALIDATE:-0}" == "1" ]]; then
  if [[ "${branch_name}" == "6.6.x" && "${resolved_mode}" == "vite" ]]; then
    echo "Skipping browser validation for 6.6.x Vite compile-only mode. The 6.6 ADMIN_VITE feature is non-toggleable in this lane; browser/admin validation runs on webpack." >&2
  elif [[ ! -d "${PLUGIN_ROOT}/node_modules/@playwright/test" ]]; then
    echo "Playwright dependencies are missing. Run 'npm install' in ${PLUGIN_ROOT} before enabling CI_ADMIN_BROWSER_VALIDATE=1." >&2
    exit 1
  else
    (
      cd "${PLUGIN_ROOT}"
      BASE_URL="${BASE_URL}" \
        SHOPWARE_REF="${branch_name}" \
        ADMIN_BUILD_MODE="${resolved_mode}" \
        PLAYWRIGHT_OUTPUT_DIR="${PLUGIN_ROOT}/var/playwright-results/${branch_name}/${resolved_mode}/admin" \
        npm run test:e2e:admin -- --reporter=list
    )
  fi
fi

echo "Administration build and login-shell smoke passed for ${SHOPWARE_DIR} (${branch_name:-unknown}, ${resolved_mode})."
