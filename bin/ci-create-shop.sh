#!/usr/bin/env bash
# Exits 3 when the shop does not come up, so a broken shop is not taken for a finding about the
# archive that is tested on it.
set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=bin/lib/lane.sh
source "${PLUGIN_ROOT}/bin/lib/lane.sh"

readonly SHOP_URL="http://127.0.0.1:8000"
readonly PINNED_PACKAGES_PATTERN='^(symfony/(?!flex$)|doctrine/)'

shop_dir=""
shopware_version=""
php_version=""
packages_before=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --php-version) php_version="$2"; shift 2 ;;
    --packages-before) packages_before="$2"; shift 2 ;;
    -*) echo "Unknown argument: $1" >&2; exit 2 ;;
    *)
      if [[ -z "${shop_dir}" ]]; then
        shop_dir="$1"
      elif [[ -z "${shopware_version}" ]]; then
        shopware_version="$1"
      else
        echo "Unexpected argument: $1" >&2
        exit 2
      fi
      shift
      ;;
  esac
done

if [[ -z "${shop_dir}" || -z "${shopware_version}" || -z "${php_version}" ]]; then
  echo "Usage: bin/ci-create-shop.sh <dir> <shopware-version> --php-version <version> [--packages-before <timestamp>]" >&2
  exit 2
fi

setup_failure() {
  echo "Shop setup failed: $1" >&2
  exit 3
}

echo "== creating the project"
shopware-cli project create "${shop_dir}" "${shopware_version}" --docker --no-audit --php-version "${php_version}" --no-interaction
shop_dir="$(cd "${shop_dir}" && pwd)"
cd "${shop_dir}"

read -ra compose <<< "$(lane_detect_compose_cmd)"

# The search service stays down, so the shop must not expect it. The development mode lets the
# profile fetch in the UCP checks reach http://127.0.0.1.
cat > compose.override.yaml <<'YAML'
services:
  web:
    environment:
      SHOPWARE_ES_ENABLED: "0"
      SHOPWARE_ES_INDEXING_ENABLED: "0"
      COMPOSER_POLICY_ADVISORIES_BLOCK: "0"
      SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE: "1"
YAML

if [[ -n "${packages_before}" ]]; then
  echo "== pinning the Symfony and Doctrine packages to their releases before ${packages_before}"
  pins="$("${PLUGIN_ROOT}/bin/ci-versions-as-of-date.sh" composer.lock "${PINNED_PACKAGES_PATTERN}" "${packages_before}" "$(mktemp -d)")"
  if [[ -n "${pins}" ]]; then
    mapfile -t pins <<< "${pins}"
    pinned_names=()
    with_options=()
    for pin in "${pins[@]}"; do
      pinned_names+=("${pin%%:*}")
      with_options+=(--with "${pin}")
    done
    # The cache directory is the one shopware-cli mounts for its own Composer run.
    mkdir -p "${HOME}/.composer"
    "${compose[@]}" run --rm --no-deps -T -v "${HOME}/.composer:/tmp/composer" web \
      composer update "${pinned_names[@]}" "${with_options[@]}" --no-scripts --no-interaction
    for pin in "${pins[@]}"; do
      locked_version="$(jq -r --arg name "${pin%%:*}" '.packages[] | select(.name == $name) | .version' composer.lock)"
      if [[ "${locked_version}" != "${pin#*:}" ]]; then
        setup_failure "${pin%%:*} is locked at ${locked_version}, expected ${pin#*:}."
      fi
    done
    echo "pinned ${#pins[@]} packages"
  else
    echo "no package is newer than the cut-off"
  fi
fi

echo "== installing the shop"
"${compose[@]}" up -d web
# The generated health check pings through the socket, which the image's temporary setup server
# answers before the database accepts TCP connections.
for _ in {1..60}; do
  if "${compose[@]}" exec -T database mariadb-admin ping -h 127.0.0.1 --protocol=tcp -uroot -proot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
"${compose[@]}" exec -T database mariadb-admin ping -h 127.0.0.1 --protocol=tcp -uroot -proot --silent >/dev/null 2>&1 \
  || setup_failure "the database does not accept TCP connections."
web bin/console system:install --basic-setup --force

lane_wait_for_200 "${SHOP_URL}/" || setup_failure "the storefront does not answer."
lane_wait_for_200 "${SHOP_URL}/admin" || setup_failure "the Administration does not answer."

echo "== taking the snapshot"
snapshot_dir="${shop_dir}.snapshot"
mkdir -p "${snapshot_dir}"
rsync -a --delete --exclude=/var/cache/ --exclude=/var/log/ "${shop_dir}/" "${snapshot_dir}/files/"
"${compose[@]}" exec -T database mariadb-dump -uroot -proot --single-transaction --routines shopware > "${snapshot_dir}/database.sql"

echo "shop ready at ${SHOP_URL}, snapshot in ${snapshot_dir}"
