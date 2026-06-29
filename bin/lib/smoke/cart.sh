#!/usr/bin/env bash
# shellcheck shell=bash
# bin/lib/smoke/cart.sh — cart create / get / update / cancel. Sourced by ci-smoke.sh;
# uses resolved_product_id / resolved_title / resolved_price from the catalog stage.

smoke_cart() {
  echo ">>> smoke: cart"

  local cart_create_payload cart_json cart_id cart_get_json cart_update_payload cart_updated_json cart_canceled_json
  cart_create_payload="$(jq -cn --arg id "${resolved_product_id}" --arg title "${resolved_title}" --argjson price "${resolved_price}" '{line_items: [{item: {id: $id, title: $title, price: $price}, quantity: 1}]}')"
  cart_json="$(curl_required 'cart.create' -X POST "${BASE_URL}/ucp/v1/carts" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "${cart_create_payload}")"
  assert_jq "${cart_json}" 'Expected cart.create to create one line item.' '.line_items | length == 1'
  cart_id="$(printf '%s' "${cart_json}" | jq -r '.id')"

  cart_get_json="$(curl_required 'cart.get' "${BASE_URL}/ucp/v1/carts/${cart_id}" -H "${ucp_agent_header}")"
  assert_jq "${cart_get_json}" 'Expected cart.get to return the cart id.' '.id != null and .id != ""'

  cart_update_payload="$(jq -cn --arg cartId "${cart_id}" --arg id "${resolved_product_id}" --arg title "${resolved_title}" --argjson price "${resolved_price}" '{id: $cartId, line_items: [{item: {id: $id, title: $title, price: $price}, quantity: 2}]}')"
  cart_updated_json="$(curl_required 'cart.update' -X PATCH "${BASE_URL}/ucp/v1/carts/${cart_id}" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "${cart_update_payload}")"
  assert_jq "${cart_updated_json}" 'Expected cart.update to change the line-item quantity.' '.line_items[0].quantity == 2'

  cart_canceled_json="$(curl_required 'cart.cancel' -X POST "${BASE_URL}/ucp/v1/carts/${cart_id}/cancel" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json')"
  assert_jq "${cart_canceled_json}" 'Expected cart.cancel to empty the cart.' '.line_items | length == 0'
}
