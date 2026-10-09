#!/usr/bin/env bash

set -euo pipefail

outline() {
  awk '
    /^# / { if (heading != "") print heading " (bullets: " count ")"; heading = $0; count = 0; next }
    /^- / { count++ }
    END { if (heading != "") print heading " (bullets: " count ")" }
  ' "$1"
}

english="$(outline CHANGELOG.md)"
german="$(outline CHANGELOG_de-DE.md)"

if [[ -z "${english}" ]]; then
  echo 'FAIL: CHANGELOG.md has no heading.' >&2
  exit 1
fi

if [[ "${english}" != "${german}" ]]; then
  echo 'FAIL: CHANGELOG.md and CHANGELOG_de-DE.md differ in their headings or bullet counts:' >&2
  diff --label CHANGELOG.md --label CHANGELOG_de-DE.md <(echo "${english}") <(echo "${german}") >&2 || true
  exit 1
fi

echo "ok: both changelogs have the same $(wc -l <<<"${english}") headings and bullet counts."
