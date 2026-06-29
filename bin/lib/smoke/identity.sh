#!/usr/bin/env bash
# shellcheck shell=bash
# bin/lib/smoke/identity.sh — OAuth/tokenization stubs (501) and the UCP-Agent
# request-time guard (422). Sourced by ci-smoke.sh.

smoke_identity() {
  echo ">>> smoke: identity"

  local oauth_body_file tokenize_body_file oauth_status no_agent_status tokenize_status
  oauth_body_file="$(mktemp)"
  tokenize_body_file="$(mktemp)"

  oauth_status="$(curl -sS -o "${oauth_body_file}" -w '%{http_code}' "${BASE_URL}/.well-known/oauth-authorization-server")"
  if [[ "${oauth_status}" != "501" ]]; then
    echo "Expected OAuth metadata endpoint to return 501, got ${oauth_status}." >&2
    cat "${oauth_body_file}" >&2
    rm -f "${oauth_body_file}" "${tokenize_body_file}"
    exit 1
  fi

  # Runtime requests must carry a UCP-Agent header (ucp-php-sdk request-time validation).
  # ucp_status sends no agent header here (UCP_AGENT_HEADER is unset in this script), so the
  # request must be rejected with 422 before reaching the capability.
  echo "Verifying the UCP-Agent header is required for runtime requests."
  no_agent_status="$(ucp_status -X POST "${BASE_URL}/ucp/v1/catalog/search" -H 'content-type: application/json' -H "Idempotency-Key: $(next_idempotency_key)" -d '{"query":"smoke","limit":1}')"
  if [[ "${no_agent_status}" != "422" ]]; then
    echo "Expected a runtime request without a UCP-Agent header to return 422, got ${no_agent_status}." >&2
    rm -f "${oauth_body_file}" "${tokenize_body_file}"
    exit 1
  fi

  tokenize_status="$(curl -sS -o "${tokenize_body_file}" -w '%{http_code}' -X POST "${BASE_URL}/ucp/v1/tokenize" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d '{"type":"tokenized","handler_id":"test","credential":{"type":"test"},"binding":{"checkout_id":"test"}}')"
  if [[ "${tokenize_status}" != "501" ]]; then
    echo "Expected tokenization endpoint to return 501, got ${tokenize_status}." >&2
    cat "${tokenize_body_file}" >&2
    rm -f "${oauth_body_file}" "${tokenize_body_file}"
    exit 1
  fi

  rm -f "${oauth_body_file}" "${tokenize_body_file}"

  # NOTE: strict-signature acceptance/rejection is intentionally NOT asserted here. This smoke
  # sends unsigned requests, and flipping signaturePolicy=strict at runtime did not reject the
  # no-signature request (the SDK rejects bad signatures, not absent ones, on this path). Signed
  # request verification is covered by the conformance suite (bin/validate-ucp-store.sh
  # conformance) and the manual-testing doc.
}
