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

db_query() {
  # shellcheck disable=SC2154
  "${compose[@]}" exec -T database mariadb -N -uroot -proot shopware -e "$1"
}

db_table_exists() {
  local table_name="$1"
  local result

  # shellcheck disable=SC2154
  result="$("${compose[@]}" exec -T database mariadb -N -uroot -proot -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'shopware' AND table_name = '${table_name}';")"

  [[ "${result}" == "1" ]]
}
