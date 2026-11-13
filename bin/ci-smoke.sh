#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-smoke.sh <shopware-dir>" >&2
  exit 1
fi

for dependency in jq rsync; do
  if ! command -v "${dependency}" >/dev/null 2>&1; then
    echo "Required dependency '${dependency}' is not available." >&2
    exit 1
  fi
done

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHOPWARE_DIR="$(cd "$1" && pwd)"
SDK_ROOT="${SDK_ROOT:-${PLUGIN_ROOT}/../ucp-php-sdk}"
SMOKE_MODE="${CI_SMOKE_MODE:-}"
SKIP_PLUGIN="${CI_SMOKE_SKIP_PLUGIN:-0}"
# Where the SDK comes from: "packagist" resolves the exact versions composer.json pins, which
# is what a merchant installs; "path" stages the SDK_ROOT checkout and relabels it as those
# versions, which is how a moving SDK branch gets tested before it is released. Default to the
# honest one -- a job that blocks merges must not test a branch wearing a release's version.
UCP_SDK_SOURCE="${UCP_SDK_SOURCE:-packagist}"

# shellcheck source=bin/lib/lane.sh
source "${PLUGIN_ROOT}/bin/lib/lane.sh"

SHOPWARE_BRANCH="$(detect_shopware_lane)"
PLUGIN_COMPOSER_VERSION="$(jq -er '.version' "${PLUGIN_ROOT}/composer.json")"

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

if [[ "${SKIP_PLUGIN}" != "1" && "${UCP_SDK_SOURCE}" == "path" && ! -d "${SDK_ROOT}" ]]; then
  echo "SDK checkout not found at ${SDK_ROOT} (UCP_SDK_SOURCE=path)." >&2
  exit 1
fi

if [[ "${UCP_SDK_SOURCE}" != "packagist" && "${UCP_SDK_SOURCE}" != "path" ]]; then
  echo "UCP_SDK_SOURCE must be 'packagist' or 'path', got '${UCP_SDK_SOURCE}'." >&2
  exit 1
fi

read -ra compose_cmd <<< "$(lane_detect_compose_cmd)"

if [[ ! -f "${SHOPWARE_DIR}/compose.yaml" ]]; then
  echo "Missing ${SHOPWARE_DIR}/compose.yaml. In CI, run bin/ci-write-compose.sh before bin/ci-smoke.sh." >&2
  exit 1
fi

compose_files=("${SHOPWARE_DIR}/compose.yaml")
if [[ -f "${SHOPWARE_DIR}/compose.override.yaml" ]]; then
  compose_files+=("${SHOPWARE_DIR}/compose.override.yaml")
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
      SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE: "1"
      MCP_SERVER: "1"
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

write_composer_security_audit() {
  local output_file="${CI_COMPOSER_AUDIT_OUTPUT:-}"
  local temporary_file
  local audit_status=0

  if [[ -z "${output_file}" ]]; then
    return 0
  fi

  mkdir -p "$(dirname "${output_file}")"
  temporary_file="$(mktemp "${output_file}.XXXXXX")"

  if web sh -lc 'cd /var/www/html && composer audit --no-dev --format=json' >"${temporary_file}"; then
    audit_status=0
  else
    audit_status=$?
  fi

  if [[ ! -s "${temporary_file}" ]]; then
    if [[ "${audit_status}" -eq 0 ]]; then
      printf '{"advisories":{},"abandoned":[]}\n' >"${temporary_file}"
    else
      printf '{"error":"composer audit produced no JSON","exitCode":%d}\n' "${audit_status}" >"${temporary_file}"
    fi
  fi

  mv "${temporary_file}" "${output_file}"
  echo "Composer audit report written to ${output_file} (exit ${audit_status})."
}

trap cleanup EXIT

rm -rf "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce" "${SHOPWARE_DIR}/custom/ucp-php-sdk"
mkdir -p "${SHOPWARE_DIR}/custom/plugins"

if [[ "${SKIP_PLUGIN}" == "1" ]]; then
  :
else
  mkdir -p "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce"
  rsync -a --delete --exclude='.git' --exclude='.tools' --exclude='vendor' --exclude='AGENTS.md' --exclude='CONTRIBUTING.md' "${PLUGIN_ROOT}/" "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce/"
  chmod -R a+rwX "${SHOPWARE_DIR}/custom/plugins/SwagAgenticCommerce"

  if [[ "${UCP_SDK_SOURCE}" == "path" ]]; then
    mkdir -p "${SHOPWARE_DIR}/custom/ucp-php-sdk"
    rsync -a --delete \
      --exclude='.git' \
      --exclude='vendor' \
      --exclude='var' \
      --exclude='examples/bootstrap-symfony-app/var' \
      --exclude='examples/merchant-symfony-app/var' \
      "${SDK_ROOT}/" "${SHOPWARE_DIR}/custom/ucp-php-sdk/"
    chmod -R a+rwX "${SHOPWARE_DIR}/custom/ucp-php-sdk"
  fi
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
  if [[ "${SKIP_PLUGIN}" != "1" ]]; then
    if [[ "${UCP_SDK_SOURCE}" == "path" ]]; then
      "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'mkdir -p /var/www/html/custom/ucp-php-sdk'
      "${container_runtime}" cp "${SHOPWARE_DIR}/custom/ucp-php-sdk/." "${web_id}:/var/www/html/custom/ucp-php-sdk"
      "${container_runtime}" exec -u 0 "${web_id}" sh -lc 'chown -R www-data:www-data /var/www/html/custom/ucp-php-sdk && chmod -R a+rwX /var/www/html/custom/ucp-php-sdk'
    fi
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

if [[ "${SKIP_PLUGIN}" == "1" ]]; then
  web sh -lc 'cd /var/www/html \
    && { composer config --unset repositories.swag-agentic-commerce >/dev/null 2>&1 || true; } \
    && { composer config --unset repositories.ucp-sdk-core >/dev/null 2>&1 || true; } \
    && { composer config --unset repositories.ucp-sdk-symfony >/dev/null 2>&1 || true; } \
    && { composer remove --no-update --no-interaction shopware/agentic-commerce ucp-php-sdk/core ucp-php-sdk/symfony-bundle >/dev/null 2>&1 || true; }'
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
    && { composer remove --no-update --no-interaction shopware/agentic-commerce ucp-php-sdk/core ucp-php-sdk/symfony-bundle >/dev/null 2>&1 || true; }'
else
  if [[ "${UCP_SDK_SOURCE}" == "path" ]]; then
    sdk_repositories="composer config repositories.ucp-sdk-core '{\"type\":\"path\",\"url\":\"custom/ucp-php-sdk/packages/core\",\"options\":{\"symlink\":true,\"versions\":{\"ucp-php-sdk/core\":\"0.0.9\"}}}' \
      && composer config repositories.ucp-sdk-symfony '{\"type\":\"path\",\"url\":\"custom/ucp-php-sdk/packages/symfony-bundle\",\"options\":{\"symlink\":true,\"versions\":{\"ucp-php-sdk/symfony-bundle\":\"0.0.9\"}}}'"
  else
    # No path repositories: the exact versions composer.json pins come from Packagist, which is
    # the pair a merchant installs. Unset first, so a warm shop from an earlier path-mode run
    # does not keep resolving the staged checkout.
    sdk_repositories="{ composer config --unset repositories.ucp-sdk-core >/dev/null 2>&1 || true; } \
      && { composer config --unset repositories.ucp-sdk-symfony >/dev/null 2>&1 || true; }"
  fi

  web sh -lc "cd /var/www/html \
    && composer config repositories.swag-agentic-commerce '{\"type\":\"path\",\"url\":\"custom/plugins/SwagAgenticCommerce\",\"options\":{\"symlink\":true,\"versions\":{\"shopware/agentic-commerce\":\"${PLUGIN_COMPOSER_VERSION}\"}}}' \
    && ${sdk_repositories} \
    && { composer remove --no-update --no-interaction ucp-php-sdk/core ucp-php-sdk/symfony-bundle >/dev/null 2>&1 || true; } \
    && composer require --update-no-dev --no-scripts --no-interaction --no-progress --prefer-dist shopware/agentic-commerce:${PLUGIN_COMPOSER_VERSION} --with-all-dependencies"
fi

write_composer_security_audit

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
web php <<'PHP'
<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

// The packages/ucp_sdk.yaml this used to guard is gone: it duplicated services.php, which
// is the only place the SDK is configured now. The sqlite and resolve() checks it carried
// live on below, against the file that survived.

$servicesConfigPath = '/var/www/html/custom/plugins/SwagAgenticCommerce/src/Resources/config/services.php';
if (is_file($servicesConfigPath)) {
    $servicesContents = (string) file_get_contents($servicesConfigPath);

    if (str_contains($servicesContents, "env('DATABASE_URL')->resolve()")) {
        fwrite(STDERR, "Packaged UCP SDK storage must not resolve DATABASE_URL; percent-encoded DSNs must stay intact.\n");
        exit(1);
    }

    if (str_contains($servicesContents, 'sqlite:')) {
        fwrite(STDERR, "Packaged UCP SDK storage must use Shopware's DATABASE_URL, not sqlite.\n");
        exit(1);
    }
}

$connectionFactory = 'Ucp\\Sdk\\Symfony\\Bridge\\DoctrineDbal\\ConnectionFactory';
$schemaBootstrapper = 'Ucp\\Sdk\\Symfony\\Bridge\\DoctrineDbal\\SchemaBootstrapper';
if (!class_exists($connectionFactory) || !class_exists($schemaBootstrapper)) {
    fwrite(STDERR, "UCP SDK DBAL storage classes are not available.\n");
    exit(1);
}

$databaseUrl = getenv('DATABASE_URL') ?: ($_SERVER['DATABASE_URL'] ?? '');
if (!is_string($databaseUrl) || $databaseUrl === '') {
    fwrite(STDERR, "DATABASE_URL is not available for UCP SDK storage validation.\n");
    exit(1);
}

$connection = $connectionFactory::create($databaseUrl);
(new $schemaBootstrapper($connection))->ensureSchema();

foreach ([
    'ucp_signing_keys',
    'ucp_idempotency',
    'ucp_oauth_state',
    'ucp_platform_profile_cache',
    'ucp_negotiation_sessions',
    'ucp_signature_nonces',
    'ucp_discovery_windows',
    'ucp_discovery_backoff',
] as $table) {
    if (!$connection->createSchemaManager()->tablesExist([$table])) {
        fwrite(STDERR, sprintf("UCP SDK storage table %s was not created.\n", $table));
        exit(1);
    }
}

echo "UCP SDK storage schema is available on Shopware database.\n";
PHP

echo "Refreshing DAL indexes."
web php /var/www/html/bin/console dal:refresh:index >/dev/null

if [[ "${BOOTSTRAP_ONLY}" == "1" ]]; then
  echo "Bootstrap completed for ${SHOPWARE_DIR}."
  exit 0
fi

# Optionally run the integration and functional suites inside the already-booted stack. These
# tests boot a real Shopware test kernel (SHOPWARE_PROJECT_DIR unset + APP_ENV=test) and drive UCP
# routes through a real Symfony browser, rather than hitting the deployed HTTP stack. They use
# Shopware core's test base classes, which are coupled to the lane's phpunit major
# (6.5->9, 6.6->10, trunk->11), so they must run on the lane's OWN phpunit, not the plugin's
# pinned .tools 10.5. The smoke stack installs Shopware --no-dev, so pull in the dev deps here:
# bin/run.php then prefers the platform phpunit and tests/bootstrap.php registers the plugin's
# src + Tests namespaces on the platform autoloader.
if [[ "${CI_SMOKE_RUN_PHPUNIT:-0}" == "1" ]]; then
  echo "Installing Shopware dev dependencies so the PHPUnit suites run on the lane's own phpunit."
  web composer install -d /var/www/html --no-interaction --no-progress --no-scripts
  for suite in integration functional; do
    echo "Running ${suite} suite (lane phpunit, booting test kernel)."
    "${compose[@]}" exec -T \
      -e SHOPWARE_PROJECT_DIR= \
      -e APP_ENV=test \
      -w /var/www/html/custom/plugins/SwagAgenticCommerce \
      web php bin/run.php phpunit --testsuite "${suite}"
  done
fi

echo "Smoke test passed for ${SHOPWARE_DIR}."
