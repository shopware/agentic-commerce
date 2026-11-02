#!/usr/bin/env bash

# Fails when the lines a change adds or edits are covered less than patchFailUnder percent, per suite.
#
# Usage: bin/ci-patch-coverage.sh <compare-ref> <php-clover.xml> <admin-cobertura.xml> <patch.md>
#
# Runs diff-cover once per report, so a well covered suite never hides a poorly covered one. diff-cover
# skips a changed file its report does not list, which would pass uncovered code, so every changed
# source file of a suite must first appear in that suite's report. Both reports list every source file,
# loaded or not. A change without executable lines in a suite passes that suite.
# Needs diff-cover on PATH, jq, and the compare ref in the local history.

set -euo pipefail

if [[ $# -ne 4 ]]; then
  echo "Usage: bin/ci-patch-coverage.sh <compare-ref> <php-clover.xml> <admin-cobertura.xml> <patch.md>" >&2
  exit 2
fi

compare_ref="$1"
php_report="$2"
admin_report="$3"
patch_file="$4"

fail_under="$(jq -er '.patchFailUnder | select(type == "number" and . >= 0 and . <= 100)' .github/coverage-floor.json)"
work_dir="$(mktemp -d)"
trap 'rm -rf "${work_dir}"' EXIT

status=0
mkdir -p "$(dirname "${patch_file}")"
printf '### Patch coverage\n\nChanged executable lines must be at least %s%% covered in each suite.\n' "${fail_under}" >"${patch_file}"

for suite in php admin; do
  if [[ "${suite}" == php ]]; then report="${php_report}"; else report="${admin_report}"; fi

  # Changed paths come from git with -z and stay inside Python, so no file name reaches a shell.
  if ! python3 -I - "${compare_ref}" "${suite}" "${report}" >"${work_dir}/${suite}-missing.txt" <<'PYTHON'
import subprocess
import sys
import xml.etree.ElementTree as ET

compare_ref, suite, report = sys.argv[1:]

changed = subprocess.run(
    ['git', 'diff', '--name-only', '-z', '--diff-filter=AMR', f'{compare_ref}...HEAD', '--'],
    check=True, capture_output=True,
).stdout.decode().split('\0')

if suite == 'php':
    in_scope = [path for path in changed if path.startswith('src/') and path.endswith('.php')]
    listed = [element.get('name', '') for element in ET.parse(report).getroot().iter('file')]
    missing = [path for path in in_scope if not any(name.endswith('/' + path) for name in listed)]
else:
    admin_source = 'src/Resources/app/administration/src/'
    in_scope = [
        path for path in changed
        if path.startswith(admin_source) and path.endswith('.js') and not path.endswith('.spec.js')
    ]
    listed = {element.get('filename') for element in ET.parse(report).getroot().iter('class')}
    missing = [path for path in in_scope if path not in listed]

for path in missing:
    print(path)
PYTHON
  then
    echo "FAIL: cannot compare the ${suite} report with the changed files." >&2
    status=1
    continue
  fi

  if [[ -s "${work_dir}/${suite}-missing.txt" ]]; then
    {
      printf '\n#### %s\n\nFAIL: changed source files missing from the %s report:\n\n' "${suite}" "${suite}"
      sed 's/`//g; s/^/- `/; s/$/`/' "${work_dir}/${suite}-missing.txt"
    } >>"${patch_file}"
    echo "FAIL: changed ${suite} source files are missing from ${report}." >&2
    status=1
    continue
  fi

  suite_status=0
  diff-cover "${report}" --compare-branch="${compare_ref}" --ignore-staged --ignore-unstaged \
    --fail-under="${fail_under}" --format "markdown:${work_dir}/${suite}.md" || suite_status=$?

  printf '\n#### %s\n\n' "${suite}" >>"${patch_file}"
  if [[ -s "${work_dir}/${suite}.md" ]]; then
    cat "${work_dir}/${suite}.md" >>"${patch_file}"
  fi
  if [[ "${suite_status}" -ne 0 ]]; then
    printf '\nFAIL: %s patch coverage is below %s%%, or diff-cover failed (exit %s).\n' "${suite}" "${fail_under}" "${suite_status}" >>"${patch_file}"
    status=1
  fi
done

exit "${status}"