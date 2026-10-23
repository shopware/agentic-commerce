#!/usr/bin/env bash

# Merges the acceptance blob reports of a run into one HTML report and writes the failed and flaky
# tests to the step summary. Test failures are reported, not returned: the acceptance jobs already
# fail for them. This fails only when there is nothing to merge or the merge writes no report.
#
# Usage: bin/ci-acceptance-report.sh <blob-dir> <report-dir>

set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: bin/ci-acceptance-report.sh <blob-dir> <report-dir>" >&2
  exit 2
fi

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mkdir -p "$1" "$2"
blob_dir="$(cd "$1" && pwd)"
report_dir="$(cd "$2" && pwd)"

if ! compgen -G "${blob_dir}/*.zip" >/dev/null; then
  echo "FAIL: no blob reports in ${blob_dir}. Did every acceptance job fail before running the suite?" >&2
  exit 1
fi

# Without -c, merge-reports does not load the suite's config, which would demand APP_URL.
(
  cd "${PLUGIN_ROOT}/tests/acceptance"
  PLAYWRIGHT_HTML_OUTPUT_DIR="${report_dir}/html" PLAYWRIGHT_HTML_OPEN=never \
    PLAYWRIGHT_JSON_OUTPUT_FILE="${report_dir}/report.json" \
    npx playwright merge-reports --reporter=html,json "${blob_dir}"
) || true

if [[ ! -s "${report_dir}/report.json" || ! -s "${report_dir}/html/index.html" ]]; then
  echo "FAIL: merging the blob reports in ${blob_dir} wrote no report." >&2
  exit 1
fi

{
  echo "### Acceptance report"
  echo
  jq -r '.stats | "\(.expected // 0) passed, \(.unexpected // 0) failed, \(.flaky // 0) flaky, \(.skipped // 0) skipped. The HTML report (tests tagged by lane) is the acceptance-report artifact."' \
    "${report_dir}/report.json"
  echo
  jq -r '
    [.suites[] | recurse(.suites[]?) | .specs[]? as $spec | $spec.tests[]
      | select(.status == "unexpected" or .status == "flaky")
      | "| \(if .status == "unexpected" then "failed" else "flaky" end) | \(.projectName) | `\($spec.file):\($spec.line) \($spec.title | gsub("\\|"; "\\|"))` |"]
    | unique
    | if length == 0 then "No failed or flaky tests." else (["| Status | Project | Test |", "| --- | --- | --- |"] + .)[] end
  ' "${report_dir}/report.json"
} | tee -a "${GITHUB_STEP_SUMMARY:-/dev/null}"