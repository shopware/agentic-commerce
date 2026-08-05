#!/usr/bin/env bash
# bin/lib/lane.sh
#
# Shared container helpers for the smoke scripts (ci-smoke / ci-admin-smoke /
# ci-storefront-smoke). Source this file (do not execute it) AFTER defining the
# compose invocation in the sourcing script:
#
#     compose=(docker compose -f compose.yaml ...)   # the full compose command + files
#     container_runtime="docker"                      # compose_cmd[0]
#     source "$(dirname "${BASH_SOURCE[0]}")/lib/lane.sh"
#
# All helpers below operate on those two globals.

if [[ -n "${LANE_LIB_SOURCED:-}" ]]; then
  return 0
fi
LANE_LIB_SOURCED=1

# lane_detect_compose_cmd — print "docker compose" or "podman compose"; exit if neither.
lane_detect_compose_cmd() {
  if command -v docker >/dev/null 2>&1; then
    printf 'docker compose\n'
  elif command -v podman >/dev/null 2>&1; then
    printf 'podman compose\n'
  else
    echo "Neither docker nor podman is available." >&2
    exit 1
  fi
}

# detect_base_url — print APP_URL from the lane's compose.yaml, else the localhost default.
detect_base_url() {
  local detected
  # shellcheck disable=SC2154  # SHOPWARE_DIR is provided by the sourcing script
  detected="$(sed -nE 's/^[[:space:]]*APP_URL:[[:space:]]*(.+)$/\1/p' "${SHOPWARE_DIR}/compose.yaml" | head -n 1)"
  if [[ -n "${detected}" ]]; then
    printf '%s\n' "${detected}"
    return 0
  fi

  printf 'http://localhost:8000\n'
}

# detect_shopware_lane — resolve the lane id (6.5.x|6.6.x|trunk) from SHOPWARE_REF or the checkout.
detect_shopware_lane() {
  if [[ "${SHOPWARE_REF:-}" == "6.5.x" || "${SHOPWARE_REF:-}" == "6.6.x" || "${SHOPWARE_REF:-}" == "trunk" ]]; then
    printf '%s\n' "${SHOPWARE_REF}"
    return 0
  fi

  # shellcheck disable=SC2154  # SHOPWARE_DIR is provided by the sourcing script
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

# web <cmd>...   — run a command in the web service container.
web() {
  # shellcheck disable=SC2154  # compose is provided by the sourcing script
  "${compose[@]}" exec -T web "$@"
}

web_container_id() {
  # shellcheck disable=SC2154
  "${compose[@]}" ps -a -q web
}

web_is_running() {
  # shellcheck disable=SC2154
  [[ -n "$("${compose[@]}" ps -q web)" ]]
}

web_root_mount_type() {
  local web_id
  web_id="$(web_container_id)"

  if [[ -z "${web_id}" ]]; then
    return 1
  fi

  # shellcheck disable=SC2154  # container_runtime is provided by the sourcing script
  "${container_runtime}" inspect \
    --format '{{range .Mounts}}{{if eq .Destination "/var/www/html"}}{{.Type}}{{end}}{{end}}' \
    "${web_id}"
}

# The MariaDB image ships the `mariadb` client; the MySQL image ships `mysql`.
# Pick the matching client so db helpers work on whichever flavor the lane
# provisioned (see CI_DB_FLAVOR in bin/ci-write-compose.sh).
db_client() {
  case "${CI_DB_FLAVOR:-mariadb}" in
    mysql*)
      echo mysql
      ;;
    *)
      echo mariadb
      ;;
  esac
}

db_query() {
  # shellcheck disable=SC2154
  "${compose[@]}" exec -T database "$(db_client)" -N -uroot -proot shopware -e "$1"
}

db_table_exists() {
  local table_name="$1"
  local result

  # shellcheck disable=SC2154
  result="$("${compose[@]}" exec -T database "$(db_client)" -N -uroot -proot -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'shopware' AND table_name = '${table_name}';")"

  [[ "${result}" == "1" ]]
}
