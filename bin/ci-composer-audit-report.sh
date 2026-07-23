#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-composer-audit-report.sh <audit-directory>" >&2
  exit 1
fi

for dependency in find jq; do
  if ! command -v "${dependency}" >/dev/null 2>&1; then
    echo "Required dependency '${dependency}' is not available." >&2
    exit 1
  fi
done

AUDIT_DIRECTORY="$1"
SUMMARY_FILE="${GITHUB_STEP_SUMMARY:-}"

if [[ -z "${SUMMARY_FILE}" ]]; then
  echo "GITHUB_STEP_SUMMARY is not set; no Composer advisory summary can be written." >&2
  exit 0
fi

mapfile -t report_files < <(find "${AUDIT_DIRECTORY}" -type f -name '*.json' 2>/dev/null | sort)

valid_reports=()
invalid_report_count=0

for report_file in "${report_files[@]}"; do
  if jq -e '
    type == "object"
    and ((.advisories // {}) | type == "object" or type == "array")
  ' "${report_file}" >/dev/null 2>&1; then
    valid_reports+=("${report_file}")
  else
    invalid_report_count=$((invalid_report_count + 1))
  fi
done

temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/swag-agentic-commerce-audit-report.XXXXXX")"
trap 'rm -rf "${temporary_directory}"' EXIT
advisories_file="${temporary_directory}/advisories.json"

if [[ ${#valid_reports[@]} -eq 0 ]]; then
  printf '[]\n' >"${advisories_file}"
else
  jq -s '
    [
      .[]
      | (.advisories // {})
      | if type == "object" then
          to_entries[]
          | .key as $package
          | .value[]
          | . + {packageName: (.packageName // $package)}
        elif type == "array" then
          .[]
        else
          empty
        end
    ]
    | unique_by(
        .advisoryId
        // .cve
        // ((.packageName // "unknown") + "|" + (.title // "untitled") + "|" + (.link // ""))
      )
  ' "${valid_reports[@]}" >"${advisories_file}"
fi

advisory_count="$(jq 'length' "${advisories_file}")"
package_count="$(jq '[.[].packageName // "unknown"] | unique | length' "${advisories_file}")"
affected_packages="$(jq -r '[.[].packageName // "unknown"] | unique | sort | join(", ")' "${advisories_file}")"
report_count="${#valid_reports[@]}"
expected_report_count="${#report_files[@]}"
missing_report_count=$((3 - expected_report_count))
if [[ "${missing_report_count}" -lt 0 ]]; then
  missing_report_count=0
fi

{
  echo "### Composer security advisory report"
  echo
  echo "Composer advisories are informational for compatibility lanes and do not block validation."
  echo
  echo "| Result | Count |"
  echo "| --- | ---: |"
  echo "| Unique advisories | ${advisory_count} |"
  echo "| Affected packages | ${package_count} |"
  echo "| Valid lane reports | ${report_count} / ${expected_report_count} |"

  if [[ -n "${affected_packages}" ]]; then
    echo
    echo "**Packages:** ${affected_packages}"
  fi

  echo
  echo "Raw per-lane JSON is available in the \`composer-audit-*\` workflow artifacts."

  if [[ "${invalid_report_count}" -gt 0 || "${missing_report_count}" -gt 0 ]]; then
    echo
    echo "> Advisory collection was incomplete; ${invalid_report_count} invalid and ${missing_report_count} missing lane report(s) were ignored."
  fi
} >>"${SUMMARY_FILE}"

if [[ "${advisory_count}" -gt 0 ]]; then
  warning_message="Found ${advisory_count} unique Composer security advisories affecting ${package_count} packages; compatibility validation continued."
elif [[ "${invalid_report_count}" -gt 0 || "${missing_report_count}" -gt 0 ]]; then
  warning_message="Composer advisory collection was incomplete; compatibility validation continued."
else
  exit 0
fi

echo "::warning title=Composer security advisories::${warning_message}"
