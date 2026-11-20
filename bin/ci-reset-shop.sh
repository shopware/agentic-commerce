#!/usr/bin/env bash
set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=bin/lib/lane.sh
source "${PLUGIN_ROOT}/bin/lib/lane.sh"

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-reset-shop.sh <dir>" >&2
  exit 2
fi

shop_dir="$(cd "$1" && pwd)"
snapshot_dir="${shop_dir}.snapshot"
if [[ ! -f "${snapshot_dir}/database.sql" ]]; then
  echo "No snapshot in ${snapshot_dir}; bin/ci-create-shop.sh makes it." >&2
  exit 2
fi

cd "${shop_dir}"
read -ra compose <<< "$(lane_detect_compose_cmd)"

"${compose[@]}" stop web
# var/log stays: the logs of every scenario of a run are uploaded when one fails.
rsync -a --delete --exclude=/var/log/ "${snapshot_dir}/files/" "${shop_dir}/"
# The dump only recreates its own tables, so a table the scenario added would stay.
"${compose[@]}" exec -T database mariadb -uroot -proot -e 'DROP DATABASE IF EXISTS shopware; CREATE DATABASE shopware'
"${compose[@]}" exec -T database mariadb -uroot -proot shopware < "${snapshot_dir}/database.sql"
"${compose[@]}" up -d web

lane_wait_for_200 "http://127.0.0.1:8000/" || exit 3
