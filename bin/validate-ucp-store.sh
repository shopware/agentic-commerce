#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-}"
PROFILE_URL="${2:-}"
MODE="${3:-basic}"

if [[ -z "${BASE_URL}" ]]; then
  echo "Usage: bin/validate-ucp-store.sh <base-url> [profile-url] [basic|extended|conformance]" >&2
  exit 64
fi

BASE_URL="${BASE_URL%/}"
PROFILE_URL="${PROFILE_URL:-${BASE_URL}/.well-known/ucp}"
UCP_AGENT_HEADER="UCP-Agent: platform; profile=\"${PROFILE_URL}\""

need() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 127
  fi
}

need curl
need jq

oauth_body_file="$(mktemp)"
tokenize_body_file="$(mktemp)"
embedded_body_file="$(mktemp)"
embedded_header_file="$(mktemp)"
init_header_file="$(mktemp)"
cleanup() {
  rm -f "${oauth_body_file}" "${tokenize_body_file}" "${embedded_body_file}" "${embedded_header_file}" "${init_header_file}"
}
trap cleanup EXIT

echo "Validating UCP profile: ${PROFILE_URL}"
profile_json="$(curl -fsS "${PROFILE_URL}")"

jq -e '.ucp.version | type == "string"' >/dev/null <<<"${profile_json}"
jq -e '.ucp.services["dev.ucp.shopping"] | type == "array" and length >= 1' >/dev/null <<<"${profile_json}"
jq -e '.ucp.capabilities | type == "object"' >/dev/null <<<"${profile_json}"
jq -e '.ucp.payment_handlers | type == "object" and length == 0' >/dev/null <<<"${profile_json}"

echo "Profile transports:"
jq -r '.ucp.services["dev.ucp.shopping"][].transport' <<<"${profile_json}" | sort -u

if jq -e '.ucp.services["dev.ucp.shopping"][] | select(.transport == "mcp")' >/dev/null <<<"${profile_json}"; then
  mcp_endpoint="$(jq -r '.ucp.services["dev.ucp.shopping"][] | select(.transport == "mcp") | .endpoint' <<<"${profile_json}" | head -n1)"
  if [[ "${mcp_endpoint}" != "${BASE_URL}/ucp/mcp" ]]; then
    echo "MCP endpoint must be ${BASE_URL}/ucp/mcp, got ${mcp_endpoint}" >&2
    exit 1
  fi
fi

oauth_status="$(curl -sS -o "${oauth_body_file}" -w '%{http_code}' "${BASE_URL}/.well-known/oauth-authorization-server")"
if [[ "${oauth_status}" != "501" ]]; then
  echo "OAuth identity linking must stay unsupported until identity_linking is enabled for the sales channel. Expected 501, got ${oauth_status}." >&2
  cat "${oauth_body_file}" >&2
  exit 1
fi

tokenize_status="$(curl -sS -o "${tokenize_body_file}" -w '%{http_code}' -X POST "${BASE_URL}/ucp/v1/tokenize" -H "${UCP_AGENT_HEADER}" -H 'content-type: application/json' -H "Idempotency-Key: validate-tokenize-$(date +%s)" -d '{"credential":{"type":"card"},"binding":{"checkout_id":"validate-tokenization-probe"}}')"
if [[ "${tokenize_status}" != "501" ]]; then
  echo "Payment tokenization must stay unsupported until a Shopware-backed adapter exists. Expected 501, got ${tokenize_status}." >&2
  cat "${tokenize_body_file}" >&2
  exit 1
fi

has_transport() {
  jq -e --arg transport "$1" '.ucp.services["dev.ucp.shopping"][] | select(.transport == $transport)' >/dev/null <<<"${profile_json}"
}

jsonrpc_call() {
  local endpoint="$1"
  local method="$2"
  local params="$3"
  local id="${4:-1}"

  curl -fsS \
    -X POST "${endpoint}" \
    -H "${UCP_AGENT_HEADER}" \
    -H 'content-type: application/json' \
    -H "idempotency-key: validate-${method//./-}-${id}-$(date +%s)" \
    -d "$(jq -nc --arg method "${method}" --argjson params "${params}" --argjson id "${id}" '{jsonrpc:"2.0", id:$id, method:$method, params:$params}')"
}

run_extended_checks() {
  local query="${UCP_VALIDATE_QUERY:-music}"
  local a2a_endpoint
  local search_response
  local first_item
  local cart_id=""

  if has_transport a2a; then
    a2a_endpoint="$(jq -r '.ucp.services["dev.ucp.shopping"][] | select(.transport == "a2a") | .endpoint' <<<"${profile_json}" | head -n1)"
    echo "Validating A2A catalog.search: ${a2a_endpoint}"
    search_response="$(jsonrpc_call "${a2a_endpoint}" catalog.search "$(jq -nc --arg query "${query}" '{query:$query, limit:1}')" 101)"
    jq -e '.jsonrpc == "2.0" and (.result | type == "object")' >/dev/null <<<"${search_response}"

    first_item="$(jq -c '.result.products[0] // empty' <<<"${search_response}")"
    if [[ -n "${first_item}" ]]; then
      echo "Validating A2A cart.create"
      cart_response="$(jsonrpc_call "${a2a_endpoint}" cart.create "$(jq -nc --argjson item "${first_item}" '{line_items:[{item:$item, quantity:1}]}')" 102)"
      jq -e '.result.id | type == "string" and length > 0' >/dev/null <<<"${cart_response}"
      cart_id="$(jq -r '.result.id' <<<"${cart_response}")"
    else
      echo "Skipping A2A cart/embedded sample: catalog.search returned no item for query '${query}'."
    fi
  else
    echo "Skipping A2A checks: transport is not advertised."
  fi

  if has_transport embedded && [[ -n "${cart_id}" ]]; then
    local origin="${UCP_VALIDATE_EMBEDDED_ORIGIN:-${BASE_URL}}"
    echo "Validating embedded cart headers and bridge"
    curl -fsS \
      -D "${embedded_header_file}" \
      -o "${embedded_body_file}" \
      -H "Origin: ${origin}" \
      "${BASE_URL}/ucp/embedded/cart/${cart_id}"
    grep -qi '^content-security-policy:.*frame-ancestors' "${embedded_header_file}"
    if grep -qi '^x-frame-options:' "${embedded_header_file}"; then
      echo "Embedded responses must not emit X-Frame-Options; CSP frame-ancestors is the source of truth." >&2
      exit 1
    fi
    grep -q 'ucp.embedded.ready' "${embedded_body_file}"
  elif has_transport embedded; then
    echo "Skipping embedded body check: no sample cart id is available."
  else
    echo "Skipping embedded checks: transport is not advertised."
  fi

  if has_transport mcp; then
    local mcp_endpoint
    local session_id
    local tools_response

    mcp_endpoint="$(jq -r '.ucp.services["dev.ucp.shopping"][] | select(.transport == "mcp") | .endpoint' <<<"${profile_json}" | head -n1)"

    echo "Validating MCP tools/list: ${mcp_endpoint}"
    curl -fsS \
      -D "${init_header_file}" \
      -o /dev/null \
      -X POST "${mcp_endpoint}" \
      -H 'content-type: application/json' \
      -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"ucp-store-validator","version":"1.0"}},"id":201}'

    session_id="$(awk 'tolower($1) == "mcp-session-id:" {gsub(/\r/,"",$2); print $2; exit}' "${init_header_file}")"
    if [[ -z "${session_id}" ]]; then
      echo "MCP initialize did not return Mcp-Session-Id." >&2
      exit 1
    fi

    tools_response="$(curl -fsS \
      -X POST "${mcp_endpoint}" \
      -H 'content-type: application/json' \
      -H "mcp-session-id: ${session_id}" \
      -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":202}')"
    jq -e '.result.tools | map(.name) | index("shopware-ucp-catalog-search") and index("shopware-ucp-cart-create") and index("shopware-ucp-checkout-create") and index("shopware-ucp-order-get")' >/dev/null <<<"${tools_response}"
  else
    echo "Skipping MCP checks: transport is not advertised."
  fi
}

if [[ "${MODE}" == "basic" ]]; then
  echo "Basic UCP validation passed."
  exit 0
fi

if [[ "${MODE}" == "extended" ]]; then
  run_extended_checks
  echo "Extended UCP validation passed."
  exit 0
fi

if [[ "${MODE}" != "conformance" ]]; then
  echo "Unknown validation mode: ${MODE}" >&2
  exit 64
fi

CONFORMANCE_DIR="${UCP_CONFORMANCE_DIR:-../conformance}"
CONFORMANCE_INPUT="${UCP_CONFORMANCE_INPUT:-test_data/flower_shop/conformance_input.json}"
SIMULATION_SECRET="${UCP_SIMULATION_SECRET:-test}"

if [[ ! -d "${CONFORMANCE_DIR}" ]]; then
  echo "Conformance checkout not found at ${CONFORMANCE_DIR}. Set UCP_CONFORMANCE_DIR." >&2
  exit 66
fi

need uv

(
  cd "${CONFORMANCE_DIR}"
  uv run pytest \
    --server_url="${BASE_URL}" \
    --simulation_secret="${SIMULATION_SECRET}" \
    --conformance_input="${CONFORMANCE_INPUT}"
)
