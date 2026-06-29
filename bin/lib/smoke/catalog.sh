#!/usr/bin/env bash
# shellcheck shell=bash
# bin/lib/smoke/catalog.sh — catalog.search / lookup / product. Sourced by ci-smoke.sh.
# Exports resolved_product_id / resolved_title / resolved_price (intentionally NOT local)
# for the cart and checkout stages that follow.

smoke_catalog() {
  echo ">>> smoke: catalog"

  local search_json lookup_json product_json
  search_json="$(curl_required 'catalog.search' -X POST "${BASE_URL}/ucp/v1/catalog/search" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "$(jq -cn --arg query "${search_term}" '{query: $query, limit: 3}')")"
  assert_jq "${search_json}" 'Expected catalog.search to return between 1 and 3 products.' '(.products // .items) | length > 0 and length <= 3'

  lookup_json="$(curl_required 'catalog.lookup' -X POST "${BASE_URL}/ucp/v1/catalog/lookup" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "$(jq -cn --arg id "${product_id}" '{ids: [$id]}')")"
  assert_jq "${lookup_json}" 'Expected catalog.lookup to resolve exactly one product.' '(.products // .items) | length == 1'

  resolved_product_id="$(printf '%s' "${lookup_json}" | jq -r '(.products // .items)[0].id')"
  resolved_title="$(printf '%s' "${lookup_json}" | jq -r '(.products // .items)[0].title')"
  resolved_price="$(printf '%s' "${lookup_json}" | jq -r '(.products // .items)[0] as $product | $product.price // ((($product.variants[0].price.amount // $product.price_range.min.amount) / 100))')"

  product_json="$(curl_required 'catalog.product' "${BASE_URL}/ucp/v1/catalog/product/${product_id}" -H "${ucp_agent_header}")"
  assert_jq "${product_json}" 'Expected catalog.product to resolve the looked-up product title.' '(.product // .).title == $title' --arg title "${resolved_title}"
}
