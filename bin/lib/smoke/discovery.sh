#!/usr/bin/env bash
# shellcheck shell=bash
# bin/lib/smoke/discovery.sh — UCP profile, transports, capabilities, and fallback
# discovery files. Sourced by ci-smoke.sh; relies on its variables (BASE_URL,
# store_api_mcp_available, core_agentic_files_available, expected_transports_json) and the
# shared ucp-http helpers.

smoke_discovery() {
  echo ">>> smoke: discovery"

  profile_json="$(curl_required 'UCP profile' "${BASE_URL}/.well-known/ucp")"
  assert_jq "${profile_json}" 'Expected the profile to expose the configured lane-aware shopping transports.' '.ucp.services["dev.ucp.shopping"] | map(.transport) | sort == $expectedTransports' --argjson expectedTransports "${expected_transports_json}"
  assert_jq "${profile_json}" 'Expected the profile to expose only the enabled shopping capabilities.' '.ucp.capabilities | keys == ["dev.ucp.shopping.cart","dev.ucp.shopping.catalog","dev.ucp.shopping.checkout","dev.ucp.shopping.discount","dev.ucp.shopping.order"]'
  assert_jq "${profile_json}" 'Expected the profile to advertise the delegated invoice payment handler while the sales channel is active.' '.ucp.payment_handlers | type == "object" and has("com.shopware.invoice") and .["com.shopware.invoice"][0].config.tokenization == false'

  if [[ "${store_api_mcp_available}" == "1" ]]; then
    assert_jq "${profile_json}" 'Expected MCP transport to point at the public UCP MCP endpoint.' '.ucp.services["dev.ucp.shopping"][] | select(.transport == "mcp") | .endpoint == $endpoint' --arg endpoint "${BASE_URL}/ucp/mcp"
  else
    assert_jq "${profile_json}" 'Expected MCP transport to stay hidden when Store API MCP is unavailable.' '[.ucp.services["dev.ucp.shopping"][] | select(.transport == "mcp")] | length == 0'
  fi

  if [[ "${core_agentic_files_available}" == "0" ]]; then
    echo "Verifying fallback agentic discovery files."
    local llms_headers_file agents_headers_file agents_lowercase_headers_file llms_body_file agents_body_file agents_lowercase_body_file llms_txt agents_md agents_md_lowercase localization_next_line
    llms_headers_file="$(mktemp)"
    agents_headers_file="$(mktemp)"
    agents_lowercase_headers_file="$(mktemp)"
    llms_body_file="$(mktemp)"
    agents_body_file="$(mktemp)"
    agents_lowercase_body_file="$(mktemp)"
    llms_txt="$(fetch_required_url "${BASE_URL}/llms.txt" 'fallback /llms.txt' "${llms_headers_file}" "${llms_body_file}")"
    agents_md="$(fetch_required_url "${BASE_URL}/AGENTS.md" 'fallback /AGENTS.md' "${agents_headers_file}" "${agents_body_file}")"
    agents_md_lowercase="$(fetch_required_url "${BASE_URL}/agents.md" 'fallback /agents.md' "${agents_lowercase_headers_file}" "${agents_lowercase_body_file}")"

    if ! grep -Eiq '^content-type:[[:space:]]*text/plain; charset=utf-8' "${llms_headers_file}"; then
      echo "Expected fallback /llms.txt to use text/plain; charset=utf-8." >&2
      cat "${llms_headers_file}" >&2
      exit 1
    fi

    if ! grep -Eiq '^content-type:[[:space:]]*text/markdown; charset=utf-8' "${agents_headers_file}"; then
      echo "Expected fallback /AGENTS.md to use text/markdown; charset=utf-8." >&2
      cat "${agents_headers_file}" >&2
      exit 1
    fi

    if ! grep -Eiq '^content-type:[[:space:]]*text/markdown; charset=utf-8' "${agents_lowercase_headers_file}"; then
      echo "Expected fallback /agents.md to use text/markdown; charset=utf-8." >&2
      cat "${agents_lowercase_headers_file}" >&2
      exit 1
    fi

    if [[ "${agents_md}" != "${agents_md_lowercase}" ]]; then
      echo "Expected fallback /AGENTS.md and /agents.md to return identical content." >&2
      exit 1
    fi

    assert_contains "${llms_txt}" 'Expected fallback /llms.txt to include localization guidance.' '## Localization'
    assert_contains "${llms_txt}" 'Expected fallback /llms.txt to include the UCP profile link.' '- [UCP profile](/.well-known/ucp)'
    assert_contains "${llms_txt}" 'Expected fallback /llms.txt to use the primary agent instructions URL.' '- [Agent instructions](/AGENTS.md)'
    assert_contains "${agents_md}" 'Expected fallback /AGENTS.md to include UCP agent guidance.' '## Agentic commerce via UCP'

    localization_next_line="$(printf '%s\n' "${llms_txt}" | awk '/^## Localization$/ {getline; print; exit}')"
    if [[ "${localization_next_line}" != "- Current language:"* ]]; then
      echo "Expected fallback /llms.txt localization details to start immediately after the heading." >&2
      printf '%s\n' "${llms_txt}" >&2
      exit 1
    fi

    rm -f "${llms_headers_file}" "${agents_headers_file}" "${agents_lowercase_headers_file}" "${llms_body_file}" "${agents_body_file}" "${agents_lowercase_body_file}"
  fi
}
