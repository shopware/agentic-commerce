#!/usr/bin/env bash
# bin/lib/ucp-http.sh
#
# Reusable HTTP helpers for the UCP smoke and store-validation scripts.
# Source this file (it is not meant to be executed):
#
#     source "$(dirname "${BASH_SOURCE[0]}")/lib/ucp-http.sh"
#     ucp_http_init "https://store.example"            # builds the UCP-Agent header
#
# Every UCP runtime request requires a `UCP-Agent: <label>; profile="<uri>"` header
# (ucp-php-sdk DefaultHttpRequestContextFactory) — the curl wrappers below inject it
# automatically once `ucp_http_init` (or a manual `UCP_AGENT_HEADER=...`) is set, so a
# new request can never silently omit it. Discovery endpoints ignore the extra header.
#
# Requires: curl, jq.

if [[ -n "${UCP_HTTP_LIB_SOURCED:-}" ]]; then
  return 0
fi
UCP_HTTP_LIB_SOURCED=1

UCP_AGENT_HEADER="${UCP_AGENT_HEADER:-}"
UCP_IDEMPOTENCY_PREFIX="${UCP_IDEMPOTENCY_PREFIX:-ucp-$$}"

# ucp_http_init <base-url-or-profile-url> [agent-label]
# Derives the UCP-Agent header from the store's own profile (same-origin), which the
# runtime accepts because allowedProfileHosts defaults to the request host.
ucp_http_init() {
  local target="${1:?ucp_http_init: base or profile URL required}"
  local label="${2:-shopware-agentic-commerce}"
  local profile="${target%/}"

  case "${profile}" in
    */.well-known/ucp) ;;
    *) profile="${profile}/.well-known/ucp" ;;
  esac

  UCP_AGENT_HEADER="UCP-Agent: ${label}; profile=\"${profile}\""
}

# ucp_need <cmd>...   — exit 127 if any required command is missing.
ucp_need() {
  local cmd
  for cmd in "$@"; do
    if ! command -v "${cmd}" >/dev/null 2>&1; then
      echo "Missing required command: ${cmd}" >&2
      exit 127
    fi
  done
}

# next_idempotency_key — unique key per call, namespaced by UCP_IDEMPOTENCY_PREFIX.
next_idempotency_key() {
  if command -v uuidgen >/dev/null 2>&1; then
    printf '%s-%s' "${UCP_IDEMPOTENCY_PREFIX}" "$(uuidgen | tr '[:upper:]' '[:lower:]')"
    return 0
  fi
  printf '%s-%s' "${UCP_IDEMPOTENCY_PREFIX}" "$(openssl rand -hex 16)"
}

# Internal: emit `-H <agent-header>` as array words when the header is set.
_ucp_agent_curl() {
  local -a agent=()
  if [[ -n "${UCP_AGENT_HEADER}" ]]; then
    agent=(-H "${UCP_AGENT_HEADER}")
  fi
  # ${arr[@]+...} keeps bash 3.2 (macOS) from treating the empty array as unbound under set -u.
  curl ${agent[@]+"${agent[@]}"} "$@"
}

# ucp_status <curl-args>...   — print only the HTTP status code (for negative assertions).
ucp_status() {
  _ucp_agent_curl -sS -o /dev/null -w '%{http_code}' "$@"
}

# ucp_expect_status <expected-code> <label> <curl-args>...
# Run the request (UCP-Agent injected), print the body to stdout, and exit 1 (dumping the
# body to stderr) when the status is not <expected-code>. For 501/422/401/400 assertions.
ucp_expect_status() {
  local expected="$1" label="$2"
  shift 2

  local body_file status
  body_file="$(mktemp)"
  status="$(_ucp_agent_curl -sS -o "${body_file}" -w '%{http_code}' "$@")"
  if [[ "${status}" != "${expected}" ]]; then
    echo "Expected ${label} to return ${expected}, got ${status}." >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  cat "${body_file}"
  rm -f "${body_file}"
}

# fetch_required_url <url> <label> <headers-file> <body-file>
# GET that must return 2xx; prints the body, dumps headers+body and exits 1 otherwise.
fetch_required_url() {
  local url="$1" label="$2" headers_file="$3" body_file="$4" status

  status="$(_ucp_agent_curl -sS -D "${headers_file}" -o "${body_file}" -w '%{http_code}' "${url}")"
  if [[ ! "${status}" =~ ^[0-9]{3}$ || "${status}" -lt 200 || "${status}" -ge 300 ]]; then
    echo "Expected ${label} to return a 2xx response, got ${status}." >&2
    echo "Response headers:" >&2
    cat "${headers_file}" >&2
    echo "Response body:" >&2
    cat "${body_file}" >&2
    exit 1
  fi

  cat "${body_file}"
}

# curl_required <label> <curl-args>...   — request that must return 2xx; prints body.
curl_required() {
  local label="$1"
  shift

  local headers_file body_file status
  headers_file="$(mktemp)"
  body_file="$(mktemp)"

  status="$(_ucp_agent_curl -sS -D "${headers_file}" -o "${body_file}" -w '%{http_code}' "$@")"
  if [[ ! "${status}" =~ ^[0-9]{3}$ || "${status}" -lt 200 || "${status}" -ge 300 ]]; then
    echo "Expected ${label} to return a 2xx response, got ${status}." >&2
    echo "Response headers:" >&2
    cat "${headers_file}" >&2
    echo "Response body:" >&2
    cat "${body_file}" >&2
    rm -f "${headers_file}" "${body_file}"
    exit 1
  fi

  cat "${body_file}"
  rm -f "${headers_file}" "${body_file}"
}

# ucp_jsonrpc <endpoint> <method> <params-json> [id]   — JSON-RPC 2.0 POST; prints body.
ucp_jsonrpc() {
  local endpoint="$1" method="$2" params="$3" id="${4:-1}"

  _ucp_agent_curl -fsS \
    -X POST "${endpoint}" \
    -H 'content-type: application/json' \
    -H "idempotency-key: $(next_idempotency_key)" \
    -d "$(jq -nc --arg method "${method}" --argjson params "${params}" --argjson id "${id}" \
      '{jsonrpc:"2.0", id:$id, method:$method, params:$params}')"
}

# assert_jq <json> <message> <jq-expression> [jq-args]...   — exit 1 if the filter is falsy.
assert_jq() {
  local json="$1" message="$2" expression="$3"
  shift 3

  if ! printf '%s' "${json}" | jq -e "$@" "${expression}" >/dev/null; then
    echo "${message}" >&2
    printf '%s\n' "${json}" >&2
    exit 1
  fi
}

# assert_contains <content> <message> <expected-substring>
assert_contains() {
  local content="$1" message="$2" expected="$3"

  if [[ "${content}" != *"${expected}"* ]]; then
    echo "${message}" >&2
    printf '%s\n' "${content}" >&2
    exit 1
  fi
}

# assert_status <actual> <expected> <message>   — exact HTTP status-code match.
assert_status() {
  local actual="$1" expected="$2" message="$3"

  if [[ "${actual}" != "${expected}" ]]; then
    echo "${message} Expected ${expected}, got ${actual}." >&2
    return 1
  fi
}
