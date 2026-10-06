#!/usr/bin/env bash
# shellcheck shell=bash
# bin/lib/smoke/signature.sh — strict signature policy over the deployed HTTP stack.
# ucp:config:set writes the stored config row, which is what the runtime reads; system config
# alone cannot switch the policy. The body check pins the RFC 9421 path, since a disallowed
# profile host also answers 401. Sourced by ci-smoke.sh.

smoke_signature() {
  echo ">>> smoke: signature"

  web php /var/www/html/bin/console ucp:config:set --sales-channel="${sales_channel_id}" --signature-policy=strict

  local strict_body_file strict_status
  strict_body_file="$(mktemp)"

  strict_status="$(curl -sS -o "${strict_body_file}" -w '%{http_code}' -X POST "${BASE_URL}/ucp/v1/catalog/search" -H "${ucp_agent_header}" -H 'content-type: application/json' -d '{"query":"smoke","limit":1}')"
  if [[ "${strict_status}" != "401" ]] || ! grep -q 'Missing signature headers.' "${strict_body_file}"; then
    echo "Expected an unsigned catalog search to be rejected with 401 'Missing signature headers.' under strict, got ${strict_status}." >&2
    cat "${strict_body_file}" >&2
    rm -f "${strict_body_file}"
    exit 1
  fi

  rm -f "${strict_body_file}"

  # The unsigned e2e step after this script reuses the stack.
  web php /var/www/html/bin/console ucp:config:set --sales-channel="${sales_channel_id}" --signature-policy=log
}