#!/usr/bin/env bash
# The older run pins the packages released before its Shopware version, which is what a shop that
# was last updated then has.
set -euo pipefail

LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/lib" && pwd)"
readonly REPOSITORY="${GITHUB_REPOSITORY:-shopware/agentic-commerce}"
readonly OLDER_THAN_DAYS=90
readonly SHOPWARE_LINES=(6.5 6.6 6.7)

declare -A pinned_sha256=(
  [1.3.0]=91290aefa2b697d699d023421fc21fa7593e73b072133ec058831e40ec49ddc7
  [1.4.0]=bd680dee5557bee9298e89a991ce380347e060a99bf41ca28d0f8c04c85878ba
)

php_version_of() {
  case "$1" in
    6.5) echo 8.2 ;;
    6.6) echo 8.3 ;;
    *) echo 8.4 ;;
  esac
}

output_dir=""
archive_under_test=""
shopware_version_by_line=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --output-dir) output_dir="$2"; shift 2 ;;
    --archive-under-test) archive_under_test="$2"; shift 2 ;;
    --shopware-versions) shopware_version_by_line="$2"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

if [[ -z "${output_dir}" ]]; then
  echo "Missing --output-dir." >&2
  exit 2
fi

if [[ -z "${shopware_version_by_line}" ]]; then
  shopware_version_by_line='{}'
fi
if ! jq -e 'type == "object"' <<< "${shopware_version_by_line}" >/dev/null 2>&1; then
  echo "--shopware-versions must be a JSON object, got: ${shopware_version_by_line}" >&2
  exit 2
fi

download_release_archive() {
  local tag="$1" destination="$2" download_dir
  download_dir="$(mktemp -d)"
  gh release download "${tag}" --repo "${REPOSITORY}" --pattern SwagAgenticCommerce.zip --dir "${download_dir}"
  if [[ -n "${pinned_sha256[${tag}]:-}" ]]; then
    local actual
    actual="$(sha256sum "${download_dir}/SwagAgenticCommerce.zip" | cut -d' ' -f1)"
    if [[ "${actual}" != "${pinned_sha256[${tag}]}" ]]; then
      echo "The ${tag} archive has SHA-256 ${actual}, expected ${pinned_sha256[${tag}]}." >&2
      exit 1
    fi
  fi
  mkdir -p "$(dirname "${destination}")"
  mv "${download_dir}/SwagAgenticCommerce.zip" "${destination}"
  rmdir "${download_dir}"
}

latest_tag="$(gh api "repos/${REPOSITORY}/releases/latest" --jq .tag_name)"
echo "latest release: ${latest_tag}" >&2

base_tags=(1.3.0 1.4.0)
if [[ "${latest_tag}" != "1.3.0" && "${latest_tag}" != "1.4.0" ]]; then
  base_tags+=("${latest_tag}")
fi
for tag in "${base_tags[@]}"; do
  download_release_archive "${tag}" "${output_dir}/base/SwagAgenticCommerce-${tag}.zip"
  echo "base archive: ${tag}" >&2
done

if [[ -n "${archive_under_test}" ]]; then
  download_release_archive "${archive_under_test}" "${output_dir}/under-test/SwagAgenticCommerce.zip"
  echo "archive under test: the ${archive_under_test} release" >&2
fi

core_releases="$(curl -fsS --retry 3 https://repo.packagist.org/p2/shopware/core.json | jq -L "${LIB_DIR}" -c '
  include "packagist";
  [expanded_releases("shopware/core")[]
    | select(.version | test("^v?[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$"))
    | {version: (.version | ltrimstr("v")), time}]')"

newest_patch_of() {
  local line="$1" newest_before_epoch="$2"
  jq -L "${LIB_DIR}" -r --arg line "${line}" --argjson limit "${newest_before_epoch}" '
    include "packagist";
    map(select((.version | startswith($line + ".")) and (.time | epoch) <= $limit))
    | sort_by(.version | split(".") | map(tonumber)) | last | .version // empty' <<< "${core_releases}"
}

release_time_of() {
  jq -r --arg version "$1" '.[] | select(.version == $version) | .time' <<< "${core_releases}"
}

now_epoch="$(date -u +%s)"
older_limit_epoch="$(date -u -d "${OLDER_THAN_DAYS} days ago" +%s)"

entries=()
add_entry() {
  local line="$1" packages="$2" shopware_version="$3" packages_before="${4:-}"
  entries+=("$(jq -nc --arg line "${line}" --arg packages "${packages}" --arg shopware_version "${shopware_version}" \
    --arg php_version "$(php_version_of "${line}")" --arg packages_before "${packages_before}" \
    '{line: $line, packages: $packages, shopware_version: $shopware_version, php_version: $php_version, packages_before: $packages_before}')")
  echo "${line} ${packages}: Shopware ${shopware_version}${packages_before:+, packages before ${packages_before}}" >&2
}

for line in "${SHOPWARE_LINES[@]}"; do
  pinned_version="$(jq -r --arg line "${line}" '.[$line] // empty' <<< "${shopware_version_by_line}")"
  if [[ -n "${pinned_version}" ]]; then
    if [[ ! "${pinned_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ || "${pinned_version}" != "${line}."* ]]; then
      echo "Shopware version '${pinned_version}' is not a ${line} release." >&2
      exit 2
    fi
    fresh_version="${pinned_version}"
    older_version="${pinned_version}"
  else
    fresh_version="$(newest_patch_of "${line}" "${now_epoch}")"
    older_version="$(newest_patch_of "${line}" "${older_limit_epoch}")"
  fi
  if [[ -z "${fresh_version}" || -z "${older_version}" ]]; then
    echo "Packagist lists no Shopware ${line} release for the run (fresh '${fresh_version}', older '${older_version}')." >&2
    exit 1
  fi
  older_release_time="$(release_time_of "${older_version}")"
  if [[ -z "${older_release_time}" ]]; then
    echo "Packagist has no release time for Shopware ${older_version}." >&2
    exit 1
  fi

  add_entry "${line}" fresh "${fresh_version}"
  add_entry "${line}" older "${older_version}" "${older_release_time}"
done

add_entry trunk fresh dev-trunk

matrix="$(printf '%s\n' "${entries[@]}" | jq -sc .)"
jq . <<< "${matrix}"
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
  echo "matrix=${matrix}" >> "${GITHUB_OUTPUT}"
fi
