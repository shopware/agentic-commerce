#!/usr/bin/env bash
# shellcheck shell=bash
# bin/lib/smoke/checkout.sh — checkout create / get / update / complete, secured order read, and
# signed webhook capture. Sourced by ci-smoke.sh; uses product_id / smoke_email / sales_channel_id
# / WEBHOOK_CAPTURE_URL from the orchestrator. Resolves the seeded product's title and price itself
# (one catalog.lookup) so it no longer depends on the catalog/cart smoke stages: those capability
# assertions now live in the functional suite (UcpCatalog/Cart/CheckoutFlowTest). Checkout stays in
# shell smoke only for the on-the-wire signed order webhook, which a booted kernel cannot observe.

smoke_checkout() {
  echo ">>> smoke: checkout"

  local lookup_json resolved_product_id resolved_title resolved_price
  local checkout_create_payload checkout_json checkout_id checkout_get_json checkout_update_payload checkout_updated_json
  local checkout_complete_json order_id order_context_token order_json webhook_capture_json

  # Resolve the seeded product's title and price for the checkout payload. This is data setup for
  # the webhook-driving flow, not a catalog assertion — catalog.search/lookup/product behaviour is
  # covered by the functional suite.
  lookup_json="$(curl_required 'checkout product lookup' -X POST "${BASE_URL}/ucp/v1/catalog/lookup" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "$(jq -cn --arg id "${product_id}" '{ids: [$id]}')")"
  resolved_product_id="$(printf '%s' "${lookup_json}" | jq -r '(.products // .items)[0].id')"
  resolved_title="$(printf '%s' "${lookup_json}" | jq -r '(.products // .items)[0].title')"
  resolved_price="$(printf '%s' "${lookup_json}" | jq -r '(.products // .items)[0] as $product | $product.price // ((($product.variants[0].price.amount // $product.price_range.min.amount) / 100))')"

  checkout_create_payload="$(jq -cn --arg id "${resolved_product_id}" --arg title "${resolved_title}" --arg email "${smoke_email}" --argjson price "${resolved_price}" '{line_items: [{item: {id: $id, title: $title, price: $price}, quantity: 1}], buyer: {email: $email, first_name: "Smoke", last_name: "Tester"}, fulfillment: {type: "shipping", extra: {shipping_address: {street: "Smoke Street 1", zipcode: "12345", city: "Berlin", country_code: "DE"}}}}')"
  checkout_json="$(curl_required 'checkout.create' -X POST "${BASE_URL}/ucp/v1/checkout-sessions" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "${checkout_create_payload}")"
  assert_jq "${checkout_json}" 'Expected checkout.create to produce a ready-for-complete session.' '.status == "ready_for_complete"'
  checkout_id="$(printf '%s' "${checkout_json}" | jq -r '.id')"

  checkout_get_json="$(curl_required 'checkout.get' "${BASE_URL}/ucp/v1/checkout-sessions/${checkout_id}" -H "${ucp_agent_header}")"
  assert_jq "${checkout_get_json}" 'Expected checkout.get to return the checkout session.' '.id != null and .id != ""'

  checkout_update_payload="$(jq -cn --arg checkoutId "${checkout_id}" --arg id "${resolved_product_id}" --arg title "${resolved_title}" --arg email "${smoke_email}" --argjson price "${resolved_price}" '{id: $checkoutId, line_items: [{item: {id: $id, title: $title, price: $price}, quantity: 2}], buyer: {email: $email, first_name: "Smoke", last_name: "Tester", phone_number: "+49123456789"}, fulfillment: {type: "shipping", extra: {shipping_address: {street: "Smoke Street 1", zipcode: "12345", city: "Berlin", country_code: "DE"}}}}')"
  checkout_updated_json="$(curl_required 'checkout.update' -X PATCH "${BASE_URL}/ucp/v1/checkout-sessions/${checkout_id}" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "${checkout_update_payload}")"
  assert_jq "${checkout_updated_json}" 'Expected checkout.update to change the checkout quantity.' '.line_items[0].quantity == 2'

  curl_required 'webhook capture clear' -X DELETE "${WEBHOOK_CAPTURE_URL}" >/dev/null
  checkout_complete_json="$(curl_required 'checkout.complete' -X POST "${BASE_URL}/ucp/v1/checkout-sessions/${checkout_id}/complete" -H "${ucp_agent_header}" -H "Idempotency-Key: $(next_idempotency_key)" -H 'content-type: application/json' -d "$(jq -cn --arg id "${checkout_id}" '{id: $id, payment: {}}')")"
  # The offline/invoice flow places the order unpaid, so checkout.complete must
  # report complete_in_progress (never "completed") while surfacing the created order.
  assert_jq "${checkout_complete_json}" 'Expected checkout.complete to place an unpaid Shopware order awaiting settlement.' '.status == "complete_in_progress" and .order.id != null and .order.id != ""'
  order_id="$(printf '%s' "${checkout_complete_json}" | jq -r '.order.id')"

  echo "Verifying secured order read."
  order_context_token="$(db_query "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.swagAgenticCommerce.ucpCheckout.shopwareContextToken')) FROM sales_channel_api_context WHERE sales_channel_id = UNHEX('${sales_channel_id}') AND token = '${checkout_id}' LIMIT 1;")"
  if [[ -z "${order_context_token}" || "${order_context_token}" == "NULL" ]]; then
    echo "Expected checkout metadata to contain a Shopware context token for secured order reads." >&2
    exit 1
  fi

  order_json="$(curl_required 'order.read' "${BASE_URL}/ucp/v1/orders/${order_id}" -H "${ucp_agent_header}" -H "sw-context-token: ${order_context_token}")"
  assert_jq "${order_json}" 'Expected order.read to return the created order.' '.id == $orderId' --arg orderId "${order_id}"

  webhook_capture_json="$(wait_for_capture)"
  assert_jq "${webhook_capture_json}" 'Expected the captured webhook payload to reference the created order.' '.data.payload.order_id == $orderId' --arg orderId "${order_id}"
  assert_jq "${webhook_capture_json}" 'Expected the captured webhook request to include HTTP signature headers.' '.data.headers.signature != null and .data.headers["signature-input"] != null and .data.headers["content-digest"] != null'
}
