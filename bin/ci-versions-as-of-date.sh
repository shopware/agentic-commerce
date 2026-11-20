#!/usr/bin/env bash
# Selects the newest stable release with the same major, not above the locked version, published
# before the cut-off. Fails when a matching package has no release before the cut-off.
set -euo pipefail

LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/lib" && pwd)"

if [[ $# -ne 4 ]]; then
  echo "Usage: bin/ci-versions-as-of-date.sh <composer.lock> <name-regex> <cutoff-timestamp> <cache-dir>" >&2
  exit 2
fi

lock="$1"
pattern="$2"
cutoff="$3"
cache="$4"

mkdir -p "${cache}"
has_missing_release=0

while read -r name locked_version; do
  cache_file="${cache}/${name//\//_}.json"
  if [[ ! -s "${cache_file}" ]]; then
    curl -fsS --retry 3 "https://repo.packagist.org/p2/${name}.json" -o "${cache_file}"
  fi

  version_as_of_cutoff="$(jq -L "${LIB_DIR}" -r --arg name "${name}" --arg locked "${locked_version#v}" --arg cutoff "${cutoff}" '
    include "packagist";
    def parts: ltrimstr("v") | split(".") | map(tonumber);
    ($locked | parts) as $lockedParts
    | expanded_releases($name)
    | [.[]
        | select(.version_normalized | test("^[0-9.]+$"))
        | select((.version | parts) as $candidate | $candidate[0] == $lockedParts[0] and $candidate <= $lockedParts)
        | select((.time | epoch) < ($cutoff | epoch))]
    | sort_by(.version | parts) | last | .version // empty' "${cache_file}")"

  if [[ -z "${version_as_of_cutoff}" ]]; then
    echo "${name} ${locked_version} has no stable release before ${cutoff}." >&2
    has_missing_release=1
  elif [[ "${version_as_of_cutoff}" != "${locked_version}" ]]; then
    echo "${name}:${version_as_of_cutoff}"
  fi
done < <(jq -r --arg pattern "${pattern}" '.packages[] | select(.name | test($pattern)) | "\(.name) \(.version)"' "${lock}")

exit "${has_missing_release}"
