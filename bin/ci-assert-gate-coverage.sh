#!/usr/bin/env bash

# Fails unless every job of each given workflow is aggregated by that workflow's gate.
#
# Usage: bin/ci-assert-gate-coverage.sh <workflow.yml>...
#
# The gate is the one job that runs re-actors/alls-green. It must run with `if: always()` (a skipped
# gate counts as passing), pass `jobs: ${{ toJSON(needs) }}`, and need every other job of its file.
# A job that should not block is made advisory with continue-on-error, not left outside the gate's
# needs. Jobs are compared by id, not by display name.
#
# A reusable workflow (workflow_call) must have a gate, so a skipped job inside it stays visible to
# its caller. Other workflows without a gate (release, notifications) are not checked.
# Needs yq (mikefarah) and jq.

set -euo pipefail

# Reusable workflows that need no gate: package-zip.yml has no conditional jobs and is also called by
# store-release.yml, where a gate job would only add a check.
ungated_reusable=(package-zip.yml)

if [[ $# -eq 0 ]]; then
  echo "Usage: bin/ci-assert-gate-coverage.sh <workflow.yml>..." >&2
  exit 2
fi

status=0

for workflow in "$@"; do
  workflow_json="$(yq -o=json '.' "${workflow}")"
  jobs_json="$(jq '.jobs // {}' <<<"${workflow_json}")"

  mapfile -t gates < <(jq -r '
    to_entries[]
    | select([.value.steps[]?.uses // "" | startswith("re-actors/alls-green@")] | any)
    | .key' <<<"${jobs_json}")

  if [[ ${#gates[@]} -eq 0 ]]; then
    reusable="$(jq -r '.on | type == "object" and has("workflow_call")' <<<"${workflow_json}")"
    if [[ "${reusable}" != "true" ]] || [[ " ${ungated_reusable[*]} " == *" $(basename "${workflow}") "* ]]; then
      echo "skip: ${workflow} has no gate and needs none."
      continue
    fi
  fi

  if [[ ${#gates[@]} -ne 1 ]]; then
    echo "FAIL: ${workflow} needs exactly one re-actors/alls-green gate job, found ${#gates[@]}: ${gates[*]:-none}." >&2
    status=1
    continue
  fi
  gate="${gates[0]}"

  problems="$(jq -r --arg gate "${gate}" '
    .[$gate] as $g
    | ($g.needs // [] | if type == "string" then [.] else . end) as $needs
    | (keys - [$gate]) as $jobs
    | ([$g.steps[] | select((.uses // "") | startswith("re-actors/alls-green@"))][0].with.jobs // "") as $input
    | [
        (if ($g.if // "" | gsub("[${} ]"; "")) != "always()" then "\($gate) must run with if: always()" else empty end),
        (if ($input | gsub(" "; "")) != "${{toJSON(needs)}}" then "\($gate) must pass jobs: ${{ toJSON(needs) }} to alls-green" else empty end),
        (($jobs - $needs)[] | "job \(.) is missing from \($gate).needs"),
        (($needs - $jobs)[] | "\($gate).needs names \(.), which is not a job of this workflow")
      ]
    | .[]' <<<"${jobs_json}")"

  if [[ -n "${problems}" ]]; then
    while IFS= read -r problem; do
      echo "FAIL: ${workflow}: ${problem}." >&2
    done <<<"${problems}"
    status=1
    continue
  fi

  echo "ok: ${workflow}: ${gate} aggregates all $(jq 'keys | length - 1' <<<"${jobs_json}") other job(s)."
done

exit "${status}"