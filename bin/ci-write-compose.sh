#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: bin/ci-write-compose.sh <shopware-dir> [6.5.x|6.6.x|trunk]" >&2
  exit 1
fi

SHOPWARE_DIR="$(cd "$1" && pwd)"
LANE="${2:-${SHOPWARE_REF:-}}"

if [[ -z "${LANE}" || "${LANE}" == "HEAD" ]]; then
  if [[ -f "${SHOPWARE_DIR}/src/Administration/Resources/app/administration/build.ts" ]]; then
    LANE="trunk"
  elif grep -q 'ADMIN_VITE' "${SHOPWARE_DIR}/src/Administration/Resources/app/administration/package.json" 2>/dev/null; then
    LANE="6.6.x"
  else
    LANE="6.5.x"
  fi
fi

case "${LANE}" in
  6.5.x)
    image="ghcr.io/shopware/docker-dev:php8.2-node24-caddy"
    root_version="6.5.9999999-dev"
    ;;
  6.6.x)
    image="ghcr.io/shopware/docker-dev:php8.3-node24-caddy"
    root_version="6.6.9999999-dev"
    ;;
  trunk|6.7.x)
    image="ghcr.io/shopware/docker-dev:php8.4-node24-caddy"
    root_version="6.7.9999999-dev"
    ;;
  *)
    echo "Unsupported Shopware lane '${LANE}'." >&2
    exit 1
    ;;
esac

# The database flavor is decoupled from the Shopware lane so a single lane can be
# re-run against a different engine. MySQL 8.4 enforces that a foreign key must
# reference a full unique/primary key (MariaDB and MySQL 8.0 accept a prefix), so
# a mysql84 lane catches install/migration regressions the default MariaDB misses.
DB_FLAVOR="${CI_DB_FLAVOR:-mariadb}"

case "${DB_FLAVOR}" in
  mariadb)
    database_service=$(cat <<'YAML'
  database:
    image: mariadb:latest
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_DATABASE: shopware
    command:
      - --sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
      - --log_bin_trust_function_creators=1
      - --binlog_cache_size=16M
      - --key_buffer_size=0
      - --join_buffer_size=1024M
      - --innodb_log_file_size=128M
      - --innodb_buffer_pool_size=1024M
      - --innodb_buffer_pool_instances=1
      - --group_concat_max_len=320000
      - --default-time-zone=+00:00
      - --max_binlog_size=512M
      - --binlog_expire_logs_seconds=86400
    volumes:
      - db-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mariadb-admin", "ping", "-h", "localhost", "-proot"]
      start_interval: 3s
      start_period: 10s
      interval: 5s
      timeout: 1s
      retries: 10
YAML
)
    ;;
  mysql84)
    database_service=$(cat <<'YAML'
  database:
    image: mysql:8.4
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: shopware
    command:
      - --sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
      - --log_bin_trust_function_creators=1
      - --group_concat_max_len=320000
      - --default-time-zone=+00:00
      - --innodb_buffer_pool_size=1024M
    volumes:
      - db-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-proot"]
      start_interval: 3s
      start_period: 10s
      interval: 5s
      timeout: 1s
      retries: 10
YAML
)
    ;;
  *)
    echo "Unsupported CI_DB_FLAVOR '${DB_FLAVOR}'. Use 'mariadb' or 'mysql84'." >&2
    exit 1
    ;;
esac

cat >"${SHOPWARE_DIR}/compose.yaml" <<EOF
services:
  web:
    image: ${image}
    ports:
      - "8000:8000"
      - "5173:5173"
      - "9998:9998"
      - "9999:9999"
    environment:
      APP_ENV: \${APP_ENV-prod}
      APP_DEBUG: \${APP_DEBUG-0}
      SHELL_VERBOSITY: \${SHELL_VERBOSITY--1}
      ADMIN_VITE: \${ADMIN_VITE-0}
      COMPOSER_NO_SECURITY_BLOCKING: \${COMPOSER_NO_SECURITY_BLOCKING-1}
      COMPOSER_POLICY_ADVISORIES_BLOCK: \${COMPOSER_POLICY_ADVISORIES_BLOCK-0}
      COMPOSER_ROOT_VERSION: ${root_version}
      HOST: "0.0.0.0"
      APP_URL: http://localhost:8000
      DATABASE_URL: mysql://root:root@database/shopware
      MAILER_DSN: smtp://mailer:1025
      OPENSEARCH_URL: http://opensearch:9200
      ADMIN_OPENSEARCH_URL: http://opensearch:9200
      SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE: \${SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE-1}
    volumes:
      - .:/var/www/html
    depends_on:
      database:
        condition: service_healthy

${database_service}

  mailer:
    image: axllent/mailpit

  opensearch:
    image: opensearchproject/opensearch:2
    environment:
      OPENSEARCH_INITIAL_ADMIN_PASSWORD: "c3o_ZPHo!"
      discovery.type: single-node
      plugins.security.disabled: "true"

volumes:
  db-data:
EOF

if [[ -n "${CI:-}" ]]; then
  # The Shopware dev container runs Composer as its image user while GitHub
  # checks files out as the runner user. Make the temporary checkout writable
  # so composer config/install can update composer.json, composer.lock, vendor,
  # var, and public assets through the bind mount.
  chmod -R a+rwX "${SHOPWARE_DIR}"
fi

echo "Wrote CI compose.yaml for ${LANE} to ${SHOPWARE_DIR}/compose.yaml"
