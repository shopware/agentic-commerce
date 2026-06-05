#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-smoke.sh <shopware-dir>" >&2
  exit 1
fi

for dependency in curl jq rsync; do
  if ! command -v "${dependency}" >/dev/null 2>&1; then
    echo "Required dependency '${dependency}' is not available." >&2
    exit 1
  fi
done

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHOPWARE_DIR="$(cd "$1" && pwd)"
SDK_ROOT="${SDK_ROOT:-${PLUGIN_ROOT}/../ucp-php-sdk}"
PLUGIN_ZIP="${CI_SMOKE_PLUGIN_ZIP:-}"
SMOKE_MODE="${CI_SMOKE_MODE:-}"
SKIP_PLUGIN="${CI_SMOKE_SKIP_PLUGIN:-0}"

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

SHOPWARE_BRANCH="$(detect_shopware_lane)"

plugin_composer_version() {
  case "${SHOPWARE_BRANCH}" in
    6.5.x)
      printf '6.5.9999999-dev\n'
      ;;
    6.6.x)
      printf '6.6.9999999-dev\n'
      ;;
    trunk)
      printf '6.7.9999999-dev\n'
      ;;
    *)
      printf 'dev-main\n'
      ;;
  esac
}

PLUGIN_COMPOSER_VERSION="$(plugin_composer_version)"

if [[ -z "${SMOKE_MODE}" ]]; then
  if [[ -n "${CI:-}" ]]; then
    SMOKE_MODE="cold"
  else
    SMOKE_MODE="warm"
  fi
fi

case "${SMOKE_MODE}" in
  warm|cold)
    ;;
  *)
    echo "Unsupported CI_SMOKE_MODE '${SMOKE_MODE}'. Use 'warm' or 'cold'." >&2
    exit 1
    ;;
esac

KEEP_STACK="${CI_SMOKE_KEEP_STACK:-}"
if [[ -z "${KEEP_STACK}" ]]; then
  if [[ "${SMOKE_MODE}" == "warm" ]]; then
    KEEP_STACK="1"
  else
    KEEP_STACK="0"
  fi
fi

BOOTSTRAP_ONLY="${CI_SMOKE_BOOTSTRAP_ONLY:-0}"
if [[ "${BOOTSTRAP_ONLY}" != "0" && "${BOOTSTRAP_ONLY}" != "1" ]]; then
  echo "Unsupported CI_SMOKE_BOOTSTRAP_ONLY '${BOOTSTRAP_ONLY}'. Use '0' or '1'." >&2
  exit 1
fi

if [[ "${SKIP_PLUGIN}" != "0" && "${SKIP_PLUGIN}" != "1" ]]; then
  echo "Unsupported CI_SMOKE_SKIP_PLUGIN '${SKIP_PLUGIN}'. Use '0' or '1'." >&2
  exit 1
fi

if [[ "${SKIP_PLUGIN}" == "1" && -n "${PLUGIN_ZIP}" ]]; then
  echo "CI_SMOKE_SKIP_PLUGIN cannot be combined with CI_SMOKE_PLUGIN_ZIP." >&2
  exit 1
fi

if [[ "${SKIP_PLUGIN}" == "1" && "${BOOTSTRAP_ONLY}" != "1" ]]; then
  echo "CI_SMOKE_SKIP_PLUGIN requires CI_SMOKE_BOOTSTRAP_ONLY=1." >&2
  exit 1
fi

export APP_ENV="${APP_ENV:-dev}"
export APP_DEBUG="${APP_DEBUG:-0}"
export SHELL_VERBOSITY="${SHELL_VERBOSITY:--1}"

if [[ ! -d "${SHOPWARE_DIR}" ]]; then
  echo "Shopware checkout not found at ${SHOPWARE_DIR}." >&2
  exit 1
fi

if [[ -n "${PLUGIN_ZIP}" ]]; then
  if ! command -v unzip >/dev/null 2>&1; then
    echo "Required dependency 'unzip' is not available." >&2
    exit 1
  fi

  if [[ ! -f "${PLUGIN_ZIP}" ]]; then
    echo "Plugin zip not found at ${PLUGIN_ZIP}." >&2
    exit 1
  fi

  PLUGIN_ZIP="$(cd "$(dirname "${PLUGIN_ZIP}")" && pwd)/$(basename "${PLUGIN_ZIP}")"
elif [[ "${SKIP_PLUGIN}" != "1" && ! -d "${SDK_ROOT}" ]]; then
  echo "SDK checkout not found at ${SDK_ROOT}." >&2
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
  echo "Missing ${SHOPWARE_DIR}/compose.yaml. In CI, run bin/ci-write-compose.sh before bin/ci-smoke.sh." >&2
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
WEBHOOK_CAPTURE_URL="${WEBHOOK_CAPTURE_URL:-${BASE_URL}/_action/swag-agentic-commerce/test/webhooks}"
if [[ -z "${WEBHOOK_CAPTURE_TARGET_URL:-}" ]]; then
  if [[ -z "${CI:-}" && "${BASE_URL}" != "http://localhost:8000" && "${BASE_URL}" != "https://localhost:8000" ]]; then
    WEBHOOK_CAPTURE_TARGET_URL="$(printf '%s' "${BASE_URL}" | sed -E 's#^(https?://[^/:]+)(:[0-9]+)?#\1:8000#')/_action/swag-agentic-commerce/test/webhooks"
  else
    WEBHOOK_CAPTURE_TARGET_URL="${WEBHOOK_CAPTURE_URL}"
  fi
fi

smoke_override_file="$(mktemp "${TMPDIR:-/tmp}/swag-agentic-commerce-ci-smoke.XXXXXX")"
mv "${smoke_override_file}" "${smoke_override_file}.yaml"
smoke_override_file="${smoke_override_file}.yaml"
shopware_stage_dir="$(mktemp -d "${TMPDIR:-/tmp}/swag-agentic-commerce-shopware-stage.XXXXXX")"
cat >"${smoke_override_file}" <<EOF
services:
  web:
    environment:
      SWAG_AGENTIC_COMMERCE_TEST_CAPTURE: "1"
      SWAG_AGENTIC_COMMERCE_SMOKE_SEED: "1"
EOF

compose_files+=("${smoke_override_file}")

compose=("${compose_cmd[@]}")
for compose_file in "${compose_files[@]}"; do
  compose+=(-f "${compose_file}")
done

container_runtime="${compose_cmd[0]}"

cleanup() {
  rm -rf "${shopware_stage_dir}"
  rm -f "${smoke_override_file}"

  if [[ "${KEEP_STACK}" == "1" ]]; then
    return 0
  fi

  "${compose[@]}" down -v >/dev/null 2>&1 || true
}

trap cleanup EXIT

web() {
  "${compose[@]}" exec -T web "$@"
}

web_container_id() {
  "${compose[@]}" ps -a -q web
}

web_is_running() {
  [[ -n "$("${compose[@]}" ps -q web)" ]]
}

web_root_mount_type() {
  local web_id
  web_id="$(web_container_id)"

  if [[ -z "${web_id}" ]]; then
    return 1
  fi

  "${container_runtime}" inspect \
    --format '{{range .Mounts}}{{if eq .Destination "/var/www/html"}}{{.Type}}{{end}}{{end}}' \
    "${web_id}"
}

db_query() {
  "${compose[@]}" exec -T database mariadb -N -uroot -proot shopware -e "$1"
}

db_table_exists() {
  local table_name="$1"
  local result

  result="$("${compose[@]}" exec -T database mariadb -N -uroot -proot -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'shopware' AND table_name = '${table_name}';")"

  [[ "${result}" == "1" ]]
}

assert_jq() {
  local json="$1"
  local message="$2"
  local expression="$3"
  shift 3

  if ! printf '%s' "${json}" | jq -e "$@" "${expression}" >/dev/null; then
    echo "${message}" >&2
    printf '%s\n' "${json}" >&2
    exit 1
  fi
}

idempotency_run_prefix="swag-agentic-commerce-smoke-$(date +%s)-$$"
smoke_email="${idempotency_run_prefix}@example.com"

next_idempotency_key() {
  if command -v uuidgen >/dev/null 2>&1; then
    printf '%s-%s' "${idempotency_run_prefix}" "$(uuidgen | tr '[:upper:]' '[:lower:]')"
    return 0
  fi

  printf '%s-%s' "${idempotency_run_prefix}" "$(openssl rand -hex 16)"
}

wait_for_capture() {
  local attempt

  for attempt in $(seq 1 15); do
    local response
    response="$(curl -sS "${WEBHOOK_CAPTURE_URL}")"

    if printf '%s' "${response}" | jq -e '.data != null' >/dev/null; then
      printf '%s' "${response}"
      return 0
    fi

    sleep 1
  done

  echo "Timed out waiting for webhook capture." >&2
  exit 1
}

rm -rf "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce" "${SHOPWARE_DIR}/custom/ucp-php-sdk"
mkdir -p "${SHOPWARE_DIR}/custom/plugins"

if [[ "${SKIP_PLUGIN}" == "1" ]]; then
  :
elif [[ -n "${PLUGIN_ZIP}" ]]; then
  unzip -q "${PLUGIN_ZIP}" -d "${SHOPWARE_DIR}/custom/plugins"
  if [[ ! -d "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce" ]]; then
    echo "Plugin zip must contain a top-level SwagAgenticCommerce directory." >&2
    exit 1
  fi
  chmod -R a+rwX "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce"
else
  mkdir -p "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce" "${SHOPWARE_DIR}/custom/ucp-php-sdk"
  rsync -a --delete --exclude='.git' --exclude='.tools' --exclude='vendor' "${PLUGIN_ROOT}/" "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce/"
  rsync -a --delete \
    --exclude='.git' \
    --exclude='vendor' \
    --exclude='var' \
    --exclude='examples/bootstrap-symfony-app/var' \
    --exclude='examples/merchant-symfony-app/var' \
    "${SDK_ROOT}/" "${SHOPWARE_DIR}/custom/ucp-php-sdk/"
  chmod -R a+rwX "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce" "${SHOPWARE_DIR}/custom/ucp-php-sdk"
fi

stage_shopware_checkout() {
  rsync -a --delete \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.gitlab' \
    --exclude='.claude' \
    --exclude='.cursor' \
    --exclude='.devcontainer' \
    --exclude='.idea' \
    --exclude='.phpunit.cache' \
    --exclude='.run' \
    --exclude='.vscode' \
    --exclude='vendor' \
    --exclude='var' \
    --exclude='public' \
    --exclude='tests' \
    --exclude='**/node_modules/' \
    --exclude='custom/plugins/SwagAgenticCommerce' \
    --exclude='custom/plugins/ucp-php-sdk' \
    --exclude='custom/ucp-php-sdk' \
    "${SHOPWARE_DIR}/" "${shopware_stage_dir}/"
}

"${compose[@]}" up -d --force-recreate web

sync_custom_sources_into_web_volume() {
  local web_id
  web_id="$(web_container_id)"

  if [[ -z "${web_id}" ]]; then
    echo "Unable to resolve the web container id for ${SHOPWARE_DIR}." >&2
    exit 1
  fi

  "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'rm -rf /var/www/html/custom/plugins/SwagAgenticCommerce /var/www/html/custom/plugins/ucp-php-sdk /var/www/html/custom/ucp-php-sdk && mkdir -p /var/www/html/custom/plugins'
  if [[ "${SKIP_PLUGIN}" == "1" ]]; then
    "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'chown -R www-data:www-data /var/www/html/custom && chmod -R a+rwX /var/www/html/custom'
  else
    "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'mkdir -p /var/www/html/custom/plugins/SwagAgenticCommerce'
    "${container_runtime}" cp "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce/." "${web_id}:/var/www/html/custom/plugins/SwagAgenticCommerce"
  fi
  if [[ "${SKIP_PLUGIN}" != "1" && -z "${PLUGIN_ZIP}" ]]; then
    "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'mkdir -p /var/www/html/custom/ucp-php-sdk'
    "${container_runtime}" cp "${SHOPWARE_DIR}/custom/ucp-php-sdk/." "${web_id}:/var/www/html/custom/ucp-php-sdk"
    "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'chown -R www-data:www-data /var/www/html/custom/plugins/SwagAgenticCommerce /var/www/html/custom/ucp-php-sdk && chmod -R a+rwX /var/www/html/custom/plugins/SwagAgenticCommerce /var/www/html/custom/ucp-php-sdk'
  elif [[ "${SKIP_PLUGIN}" != "1" ]]; then
    "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'chown -R www-data:www-data /var/www/html/custom/plugins/SwagAgenticCommerce && chmod -R a+rwX /var/www/html/custom/plugins/SwagAgenticCommerce'
  fi
}

bootstrap_web_volume_if_needed() {
  local web_id
  web_id="$(web_container_id)"

  if [[ -z "${web_id}" ]]; then
    echo "Unable to resolve the web container id for ${SHOPWARE_DIR}." >&2
    exit 1
  fi

  stage_shopware_checkout
  "${container_runtime}" cp "${shopware_stage_dir}/." "${web_id}:/var/www/html"
  "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'chown -R www-data:www-data /var/www/html && chmod -R u+rwX,go+rX /var/www/html && chmod -R a+rwX /var/www/html/custom'
  sync_custom_sources_into_web_volume
  "${compose[@]}" up -d web
}

if web_is_running && [[ "$(web_root_mount_type)" == "volume" ]]; then
  if [[ "${SMOKE_MODE}" == "cold" ]] || ! web test -f /var/www/html/composer.json; then
    bootstrap_web_volume_if_needed
  else
    sync_custom_sources_into_web_volume
  fi
elif ! web_is_running; then
  bootstrap_web_volume_if_needed
fi

for attempt in $(seq 1 30); do
  if web_is_running && web test -f /var/www/html/composer.json; then
    break
  fi

  if [[ "${attempt}" -eq 30 ]]; then
    echo "Timed out waiting for the web container to become ready for ${SHOPWARE_DIR}." >&2
    exit 1
  fi

  sleep 2
done

if [[ "${SKIP_PLUGIN}" == "1" || -n "${PLUGIN_ZIP}" ]]; then
  web sh -lc 'cd /var/www/html \
    && { composer config --unset repositories.swag-agentic-commerce >/dev/null 2>&1 || true; } \
    && { composer config --unset repositories.ucp-sdk-core >/dev/null 2>&1 || true; } \
    && { composer config --unset repositories.ucp-sdk-symfony >/dev/null 2>&1 || true; } \
    && { composer remove --no-update --no-interaction shopware/agentic-commerce shopware/ucp-php-sdk-core ucp-php-sdk/symfony-bundle >/dev/null 2>&1 || true; }'
fi

if [[ ! -f "${SHOPWARE_DIR}/composer.lock" && "${SHOPWARE_BRANCH}" == "6.5.x" ]]; then
  web sh -lc 'cd /var/www/html && composer update --no-dev --no-scripts --no-interaction --no-security-blocking --prefer-dist --with="shopware/conflicts:0.1.30" --with="twig/twig:^3.20,<3.27"'
else
  web sh -lc 'cd /var/www/html && composer install --no-dev --no-scripts --no-interaction --prefer-dist'
fi

if ! db_table_exists plugin; then
  web php /var/www/html/bin/console system:install --basic-setup --force
fi

if [[ "${SKIP_PLUGIN}" == "1" ]]; then
  web sh -lc 'cd /var/www/html \
    && { composer config --unset repositories.swag-agentic-commerce >/dev/null 2>&1 || true; } \
    && { composer config --unset repositories.ucp-sdk-core >/dev/null 2>&1 || true; } \
    && { composer config --unset repositories.ucp-sdk-symfony >/dev/null 2>&1 || true; } \
    && { composer remove --no-update --no-interaction shopware/agentic-commerce shopware/ucp-php-sdk-core ucp-php-sdk/symfony-bundle >/dev/null 2>&1 || true; }'
elif [[ -n "${PLUGIN_ZIP}" ]]; then
  web sh -lc 'cd /var/www/html \
    && { composer config --unset repositories.swag-agentic-commerce >/dev/null 2>&1 || true; } \
    && { composer config --unset repositories.ucp-sdk-core >/dev/null 2>&1 || true; } \
    && { composer config --unset repositories.ucp-sdk-symfony >/dev/null 2>&1 || true; } \
    && { composer remove --no-update --no-interaction shopware/agentic-commerce shopware/ucp-php-sdk-core ucp-php-sdk/symfony-bundle >/dev/null 2>&1 || true; }'
else
  web sh -lc "cd /var/www/html \
    && composer config repositories.swag-agentic-commerce '{\"type\":\"path\",\"url\":\"custom/plugins/SwagAgenticCommerce\",\"options\":{\"symlink\":true,\"versions\":{\"shopware/agentic-commerce\":\"${PLUGIN_COMPOSER_VERSION}\"}}}' \
    && composer config repositories.ucp-sdk-core '{\"type\":\"path\",\"url\":\"custom/ucp-php-sdk/packages/core\",\"options\":{\"symlink\":true,\"versions\":{\"shopware/ucp-php-sdk-core\":\"0.0.1\"}}}' \
    && composer config repositories.ucp-sdk-symfony '{\"type\":\"path\",\"url\":\"custom/ucp-php-sdk/packages/symfony-bundle\",\"options\":{\"symlink\":true,\"versions\":{\"ucp-php-sdk/symfony-bundle\":\"0.0.1\"}}}' \
    && { composer remove --no-update --no-interaction shopware/ucp-php-sdk-core ucp-php-sdk/symfony-bundle >/dev/null 2>&1 || true; } \
    && composer require --update-no-dev --no-scripts --no-interaction --no-progress --prefer-dist shopware/agentic-commerce:${PLUGIN_COMPOSER_VERSION} --with-all-dependencies"
fi

# Composer may update core service definitions while a prod container compiled
# for the previous checkout is still present. Remove it before booting console
# commands such as plugin:refresh.
web sh -lc 'cd /var/www/html && rm -rf var/cache/*'

if [[ "${SKIP_PLUGIN}" == "1" ]]; then
  echo "Core bootstrap completed for ${SHOPWARE_DIR}."
  exit 0
fi

web php /var/www/html/bin/console plugin:refresh
web php /var/www/html/bin/console cache:clear >/dev/null
web php /var/www/html/bin/console plugin:install --activate SwagAgenticCommerce

if [[ -n "${PLUGIN_ZIP}" ]]; then
  web sh -lc 'cd /var/www/html \
    && php bin/console bundle:dump \
    && php bin/console feature:dump \
    && php bin/console assets:install \
    && rm -f public/bundles/administration/administration/sw-plugin-dev.json'

  if ! web sh -lc 'test -f /var/www/html/public/bundles/swagagenticcommerce/administration/.vite/entrypoints.json'; then
    echo "Zip-installed administration Vite entrypoints were not published to public/bundles." >&2
    exit 1
  fi

  if ! web sh -lc 'test -f /var/www/html/public/bundles/swagagenticcommerce/administration/js/swag-agentic-commerce.js'; then
    echo "Zip-installed legacy administration bootstrap was not published to public/bundles." >&2
    exit 1
  fi
fi

sales_channel_id="$(db_query "SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url LIKE 'http://localhost:%' ORDER BY sales_channel_id LIMIT 1;")"
if [[ -z "${sales_channel_id}" ]]; then
  sales_channel_id="$(db_query "SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url LIKE 'http://%' ORDER BY sales_channel_id LIMIT 1;")"
fi

if [[ -z "${sales_channel_id}" ]]; then
  echo "Unable to resolve a storefront sales channel domain for smoke testing." >&2
  exit 1
fi

echo "Configuring UCP smoke sales channel ${sales_channel_id}."
ensure_sales_channel_domain_url() {
  local domain_url="$1"

  db_query "INSERT INTO sales_channel_domain (id, sales_channel_id, language_id, currency_id, snippet_set_id, url, created_at, updated_at) SELECT UNHEX(REPLACE(UUID(), '-', '')), sales_channel_id, language_id, currency_id, snippet_set_id, '${domain_url}', NOW(3), NOW(3) FROM sales_channel_domain WHERE sales_channel_id = UNHEX('${sales_channel_id}') AND NOT EXISTS (SELECT 1 FROM sales_channel_domain existing WHERE existing.url = '${domain_url}') LIMIT 1;"
}

ensure_sales_channel_domain_url "${BASE_URL}"
webhook_capture_target_base_url="${WEBHOOK_CAPTURE_TARGET_URL%/_action/swag-agentic-commerce/test/webhooks}"
if [[ "${webhook_capture_target_base_url}" != "${BASE_URL}" ]]; then
  ensure_sales_channel_domain_url "${webhook_capture_target_base_url}"
fi
web php /var/www/html/bin/console system:config:set SwagAgenticCommerce.config.active true --json --salesChannelId="${sales_channel_id}"
# The smoke runner still exercises unsigned requests directly via curl, so it opts the lane into log-only verification.
web php /var/www/html/bin/console system:config:set SwagAgenticCommerce.config.signaturePolicy log --salesChannelId="${sales_channel_id}"
web php /var/www/html/bin/console system:config:set SwagAgenticCommerce.config.webhookUrlOverride "${WEBHOOK_CAPTURE_TARGET_URL}" --salesChannelId="${sales_channel_id}"
web php /var/www/html/bin/console system:config:set SwagAgenticCommerce.config.continueUrlTemplate "${BASE_URL}/checkout/confirm?checkoutId={checkoutId}" --salesChannelId="${sales_channel_id}"

store_api_mcp_available="$(web php -r 'require "/var/www/html/vendor/autoload.php"; echo class_exists("Shopware\\Core\\Framework\\Mcp\\Controller\\StoreApiMcpServerController") ? "1" : "0";')"
enabled_transports='["rest","a2a","embedded"]'
expected_transports_json='["a2a","embedded","rest"]'
if [[ "${store_api_mcp_available}" == "1" ]]; then
  enabled_transports='["rest","a2a","embedded","mcp"]'
  expected_transports_json='["a2a","embedded","mcp","rest"]'
fi

web php /var/www/html/bin/console system:config:set SwagAgenticCommerce.config.enabledTransports "${enabled_transports}" --json --salesChannelId="${sales_channel_id}"
config_json="$(jq -cn \
  --argjson transports "${enabled_transports}" \
  --arg continueUrlTemplate "${BASE_URL}/checkout/confirm?checkoutId={checkoutId}" \
  --arg webhookUrlOverride "${WEBHOOK_CAPTURE_TARGET_URL}" \
  '{
    active: true,
    ucpVersion: "2026-04-08",
    profileUriStrategy: "domain",
    customProfileUri: null,
    enabledCapabilities: ["catalog", "cart", "discount", "checkout", "order"],
    enabledTransports: $transports,
    continueUrlTemplate: $continueUrlTemplate,
    platformAllowlist: [],
    remoteProfileAllowlist: [],
    agentAllowlist: [],
    embeddedAllowedOrigins: [],
    embeddedFrameAncestors: [],
    discoveryBudget: 10,
    webhookUrlOverride: $webhookUrlOverride,
    signaturePolicy: "log",
    idempotencyRequired: true
  }')"
db_query "INSERT INTO swag_agentic_commerce_ucp_config (sales_channel_id, config_json, created_at, updated_at) VALUES (UNHEX('${sales_channel_id}'), '${config_json}', NOW(3), NOW(3)) ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), updated_at = NOW(3);"
echo "Generating UCP smoke signing key."
web php /var/www/html/bin/console ucp:signing-keys:generate --kid="ci-smoke-$(date +%s)" >/dev/null

if [[ "$(db_query 'SELECT COUNT(*) FROM product;')" == "0" ]]; then
  echo "Seeding UCP smoke catalog."
  web php /var/www/html/bin/console swag-agentic-commerce:seed-smoke-catalog --sales-channel-id="${sales_channel_id}" >/dev/null
fi

echo "Refreshing DAL indexes for UCP smoke."
web php /var/www/html/bin/console dal:refresh:index >/dev/null

if [[ "${BOOTSTRAP_ONLY}" == "1" ]]; then
  echo "Bootstrap completed for ${SHOPWARE_DIR}."
  exit 0
fi

product_row="$(db_query "SELECT LOWER(HEX(product.id)), pt.name FROM product INNER JOIN product_translation pt ON pt.product_id = product.id WHERE product.active = 1 AND pt.name IS NOT NULL ORDER BY product.created_at ASC LIMIT 1;")"
product_id="${product_row%%$'\t'*}"
product_name="${product_row#*$'\t'}"

if [[ -z "${product_id}" || -z "${product_name}" ]]; then
  echo "Unable to resolve an active product for smoke testing." >&2
  exit 1
fi

search_term="${product_name%% *}"

profile_json="$(curl -fsS "${BASE_URL}/.well-known/ucp")"
assert_jq "${profile_json}" 'Expected the profile to expose the configured lane-aware shopping transports.' '.ucp.services["dev.ucp.shopping"] | map(.transport) | sort == $expectedTransports' --argjson expectedTransports "${expected_transports_json}"
assert_jq "${profile_json}" 'Expected the profile to expose only the enabled shopping capabilities.' '.ucp.capabilities | keys == ["dev.ucp.shopping.cart","dev.ucp.shopping.catalog","dev.ucp.shopping.checkout","dev.ucp.shopping.discount","dev.ucp.shopping.order"]'
assert_jq "${profile_json}" 'Expected the profile to expose no payment handlers until tokenization has a Shopware-backed adapter.' '.ucp.payment_handlers | type == "object" and length == 0'

if [[ "${store_api_mcp_available}" == "1" ]]; then
  assert_jq "${profile_json}" 'Expected MCP transport to point at the public UCP MCP endpoint.' '.ucp.services["dev.ucp.shopping"][] | select(.transport == "mcp") | .endpoint == $endpoint' --arg endpoint "${BASE_URL}/ucp/mcp"
else
  assert_jq "${profile_json}" 'Expected MCP transport to stay hidden when Store API MCP is unavailable.' '[.ucp.services["dev.ucp.shopping"][] | select(.transport == "mcp")] | length == 0'
fi

oauth_body_file="$(mktemp)"
oauth_status="$(curl -sS -o "${oauth_body_file}" -w '%{http_code}' "${BASE_URL}/.well-known/oauth-authorization-server")"
if [[ "${oauth_status}" != "501" ]]; then
  echo "Expected OAuth metadata endpoint to return 501, got ${oauth_status}." >&2
  cat "${oauth_body_file}" >&2
  exit 1
fi

tokenize_body_file="$(mktemp)"
tokenize_status="$(curl -sS -o "${tokenize_body_file}" -w '%{http_code}' -X POST "${BASE_URL}/ucp/v1/tokenize" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d '{"type":"tokenized","handler_id":"test","credential":{}}')"
if [[ "${tokenize_status}" != "501" ]]; then
  echo "Expected tokenization endpoint to return 501, got ${tokenize_status}." >&2
  cat "${tokenize_body_file}" >&2
  exit 1
fi

search_json="$(curl -fsS -X POST "${BASE_URL}/ucp/v1/catalog/search" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "$(jq -cn --arg query "${search_term}" '{query: $query, limit: 3}')")"
assert_jq "${search_json}" 'Expected catalog.search to return between 1 and 3 products.' '.items | length > 0 and length <= 3'

lookup_json="$(curl -fsS -X POST "${BASE_URL}/ucp/v1/catalog/lookup" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "$(jq -cn --arg id "${product_id}" '{ids: [$id]}')")"
assert_jq "${lookup_json}" 'Expected catalog.lookup to resolve exactly one product.' '.items | length == 1'

resolved_product_id="$(printf '%s' "${lookup_json}" | jq -r '.items[0].id')"
resolved_title="$(printf '%s' "${lookup_json}" | jq -r '.items[0].title')"
resolved_price="$(printf '%s' "${lookup_json}" | jq -r '.items[0].price')"

product_json="$(curl -fsS "${BASE_URL}/ucp/v1/catalog/product/${product_id}")"
assert_jq "${product_json}" 'Expected catalog.product to resolve the looked-up product title.' '.title == $title' --arg title "${resolved_title}"

cart_create_payload="$(jq -cn --arg id "${resolved_product_id}" --arg title "${resolved_title}" --argjson price "${resolved_price}" '{line_items: [{item: {id: $id, title: $title, price: $price}, quantity: 1}]}')"
cart_json="$(curl -fsS -X POST "${BASE_URL}/ucp/v1/carts" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "${cart_create_payload}")"
assert_jq "${cart_json}" 'Expected cart.create to create one line item.' '.line_items | length == 1'
cart_id="$(printf '%s' "${cart_json}" | jq -r '.id')"

cart_get_json="$(curl -fsS "${BASE_URL}/ucp/v1/carts/${cart_id}")"
assert_jq "${cart_get_json}" 'Expected cart.get to return the cart id.' '.id != null and .id != ""'

cart_update_payload="$(jq -cn --arg id "${resolved_product_id}" --arg title "${resolved_title}" --argjson price "${resolved_price}" '{line_items: [{item: {id: $id, title: $title, price: $price}, quantity: 2}]}')"
cart_updated_json="$(curl -fsS -X PATCH "${BASE_URL}/ucp/v1/carts/${cart_id}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "${cart_update_payload}")"
assert_jq "${cart_updated_json}" 'Expected cart.update to change the line-item quantity.' '.line_items[0].quantity == 2'

cart_canceled_json="$(curl -fsS -X POST "${BASE_URL}/ucp/v1/carts/${cart_id}/cancel" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json')"
assert_jq "${cart_canceled_json}" 'Expected cart.cancel to empty the cart.' '.line_items | length == 0'

checkout_create_payload="$(jq -cn --arg id "${resolved_product_id}" --arg title "${resolved_title}" --arg email "${smoke_email}" --argjson price "${resolved_price}" '{line_items: [{item: {id: $id, title: $title, price: $price}, quantity: 1}], buyer: {email: $email, first_name: "Smoke", last_name: "Tester"}, fulfillment: {type: "shipping", extra: {shipping_address: {street: "Smoke Street 1", zipcode: "12345", city: "Berlin", country_code: "DE"}}}}')"
checkout_json="$(curl -fsS -X POST "${BASE_URL}/ucp/v1/checkout-sessions" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "${checkout_create_payload}")"
assert_jq "${checkout_json}" 'Expected checkout.create to produce a ready-for-complete session.' '.status == "ready_for_complete"'
checkout_id="$(printf '%s' "${checkout_json}" | jq -r '.id')"

checkout_get_json="$(curl -fsS "${BASE_URL}/ucp/v1/checkout-sessions/${checkout_id}")"
assert_jq "${checkout_get_json}" 'Expected checkout.get to return the checkout session.' '.id != null and .id != ""'

checkout_update_payload="$(jq -cn --arg id "${resolved_product_id}" --arg title "${resolved_title}" --arg email "${smoke_email}" --argjson price "${resolved_price}" '{line_items: [{item: {id: $id, title: $title, price: $price}, quantity: 2}], buyer: {email: $email, first_name: "Smoke", last_name: "Tester", phone_number: "+49123456789"}, fulfillment: {type: "shipping", extra: {shipping_address: {street: "Smoke Street 1", zipcode: "12345", city: "Berlin", country_code: "DE"}}}}')"
checkout_updated_json="$(curl -fsS -X PATCH "${BASE_URL}/ucp/v1/checkout-sessions/${checkout_id}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "${checkout_update_payload}")"
assert_jq "${checkout_updated_json}" 'Expected checkout.update to change the checkout quantity.' '.line_items[0].quantity == 2'

curl -fsS -X DELETE "${WEBHOOK_CAPTURE_URL}" >/dev/null
checkout_complete_json="$(curl -fsS -X POST "${BASE_URL}/ucp/v1/checkout-sessions/${checkout_id}/complete" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json')"
assert_jq "${checkout_complete_json}" 'Expected checkout.complete to create a Shopware order.' '.status == "completed" and .order.id != null and .order.id != ""'
order_id="$(printf '%s' "${checkout_complete_json}" | jq -r '.order.id')"

order_json="$(curl -fsS "${BASE_URL}/ucp/v1/orders/${order_id}")"
assert_jq "${order_json}" 'Expected order.read to return the created order.' '.id == $orderId' --arg orderId "${order_id}"

webhook_capture_json="$(wait_for_capture)"
assert_jq "${webhook_capture_json}" 'Expected the captured webhook payload to reference the created order.' '.data.payload.order_id == $orderId' --arg orderId "${order_id}"
assert_jq "${webhook_capture_json}" 'Expected the captured webhook request to include HTTP signature headers.' '.data.headers.signature != null and .data.headers["signature-input"] != null and .data.headers["content-digest"] != null'

rm -f "${oauth_body_file}" "${tokenize_body_file}"
echo "Smoke test passed for ${SHOPWARE_DIR}."
