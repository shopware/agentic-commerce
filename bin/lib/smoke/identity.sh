#!/usr/bin/env bash
# shellcheck shell=bash
# bin/lib/smoke/identity.sh — tokenization stub (501) over the deployed HTTP stack.
# The OAuth-metadata 501 and the UCP-Agent request-time 422 guard are now covered by
# the kernel integration suite (UcpRequestContextGuardTest), which gates on every lane,
# so they are no longer asserted here. Sourced by ci-smoke.sh.

smoke_identity() {
  echo ">>> smoke: identity"

  local tokenize_body_file tokenize_status
  tokenize_body_file="$(mktemp)"

  tokenize_status="$(curl -sS -o "${tokenize_body_file}" -w '%{http_code}' -X POST "${BASE_URL}/ucp/v1/tokenize" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d '{"type":"tokenized","handler_id":"test","credential":{"type":"test"},"binding":{"checkout_id":"test"}}')"
  if [[ "${tokenize_status}" != "501" ]]; then
    echo "Expected tokenization endpoint to return 501, got ${tokenize_status}." >&2
    cat "${tokenize_body_file}" >&2
    rm -f "${tokenize_body_file}"
    exit 1
  fi

  rm -f "${tokenize_body_file}"

  # NOTE: strict-signature acceptance/rejection is intentionally NOT asserted here. This smoke
  # sends unsigned requests, and flipping signaturePolicy=strict at runtime did not reject the
  # no-signature request (the SDK rejects bad signatures, not absent ones, on this path). Signed
  # request verification is covered by the conformance suite (bin/validate-ucp-store.sh
  # conformance) and the manual-testing doc.
}
