# UCP AP2 Payment Status

AP2 mandate support is implemented across the UCP PHP SDK and this plugin. A
Shopware checkout can advertise the AP2 capability, sign checkout terms, require
a checkout mandate at completion, verify mandate terms deterministically, and
gate order placement behind payment authorization. Real cryptographic mandate
verification and payment processing remain PSP plugin responsibilities.

## References

- [UCP and AP2](https://ucp.dev/documentation/ucp-and-ap2/)
- [UCP AP2 mandates](https://ucp.dev/latest/specification/ap2-mandates/)
- [AP2 specification](https://ap2-protocol.org/ap2/specification/)
- [UCP payment handler guide](https://ucp.dev/latest/specification/payment-handler-guide/)
- [UCP tokenization guide](https://ucp.dev/latest/specification/tokenization-guide/)

## Implemented

| Piece | Where |
| --- | --- |
| AP2 capability advertisement | `UcpCapabilityCatalog::CONFIG_AP2_MANDATE` (`dev.ucp.shopping.ap2_mandate`, extends checkout). Opt-in via admin config and only advertised when an AP2 mandate claims verifier is registered (`CapabilityFilteringProfileContributor` + `UcpExtensionAvailability::supportsAp2Mandates()`). |
| Complete request payload | SDK `CheckoutCompleteRequest` (`payment.instruments`, `ap2.checkout_mandate`) parsed by `HttpPayloadMapper::toCheckoutCompleteRequest()` and passed through `CheckoutCapabilityInterface`/`CheckoutAdapterInterface::completeCheckout()`. MCP `shopware-ucp-checkout-complete` accepts the same payload. |
| AP2 session locking | Checkouts created/updated under negotiated AP2 persist `ap2Locked` in checkout session metadata (`CheckoutSessionManager`). Locked checkouts reject completion without `ap2.checkout_mandate` (`mandate_required`). |
| Merchant checkout signature | `Ap2MerchantAuthorizationResponseAugmenter` adds `ap2.merchant_authorization` — an ES256 detached JWS (RFC 7797, `b64:false`) over the RFC 8785-canonicalized checkout payload excluding `ap2` — signed with the active managed key (SDK `DetachedJwsService` + `DefaultCheckoutMerchantAuthorizationSigner`). |
| Checkout mandate verification | `ShopwareAp2CheckoutMandateVerifier` (tagged `ucp_sdk.ap2_checkout_mandate_verifier`, invoked by the SDK executor before adapter completion) checks expiry and compares verified claims against `ShopwareCheckoutTermsFactory` terms (integer minor units) — `mandate_scope_mismatch` / `mandate_expired` on failure. |
| Payment authorization gate | `CheckoutCompleter` authorizes the selected instrument through `PaymentAuthorizerRegistry` before `placeOrder()`; failures surface as AP2 protocol errors instead of placing the order. |
| AP2 error mapping | SDK `Ap2Exception` maps to HTTP 422 with a stable `messages[0].code` (`mandate_required`, `mandate_scope_mismatch`, `mandate_expired`, `mandate_invalid`, `mandate_unsupported`, `payment_declined`, `payment_handler_unsupported`, ...). |
| Deterministic verification boundary | Mandate parsing, term comparison, signing, and payment authorization live in PHP services with unit coverage — never in agent prompts or MCP tool descriptions. |
| Test coverage | Unit suites in both repos plus `tests/e2e/ucp/ap2-checkout.spec.js` (profile gating, `mandate_required`, `mandate_scope_mismatch`, declined payment, successful fixture completion). Smoke lanes register deterministic fixtures via `SWAG_AGENTIC_COMMERCE_TEST_AP2=1`. |

## What a payment integration must deliver

A real PSP integration (for example x402) plugs into two extension points. Both
interfaces are autoconfigured container-wide by `SwagAgenticCommerce::build()`:
a service implementing them is tagged automatically when it is autoconfigured;
otherwise tag it manually.

### 1. Mandate claims verifier (makes AP2 advertisable)

Implement `Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateClaimsVerifierInterface`
(tag `swag_agentic_commerce.ucp.ap2_mandate_claims_verifier`):

```php
public function verify(string $checkoutMandate, RequestContext $context): Ap2VerificationResult;
```

Responsibilities: verify the `ap2.checkout_mandate` SD-JWT signature and key
binding cryptographically, then return
`Ap2VerificationResult::verified($claims)` or
`Ap2VerificationResult::failed($errorCode, $reason)` (the error code surfaces
verbatim as the HTTP 422 `messages[0].code`; use stable codes such as
`mandate_invalid` or `mandate_expired`). Registering at least one implementation
is what allows the `dev.ucp.shopping.ap2_mandate` capability to be advertised.

`ShopwareAp2CheckoutMandateVerifier` then compares the returned claims against
the current checkout terms. Supported claim keys:

| Claim | Comparison |
| --- | --- |
| `checkout_id` | Required; must equal the checkout id, otherwise `mandate_scope_mismatch`. |
| `currency` | Optional; must equal the checkout currency (ISO code). |
| `total` | Optional; `{amount, currency}` or bare number — `amount` in integer **minor units**, compared against the current total. |
| `line_items` | Optional; list of `{id, quantity}` — must match the current line items exactly (set comparison). |
| `fulfillment_total` | Optional; `{amount}` or bare number in minor units, compared against fulfillment cost. |
| `exp` | Optional; unix timestamp — past values are rejected as `mandate_expired`. |

### 2. Payment authorizer (gates order placement)

Implement `Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizerInterface`
(tag `swag_agentic_commerce.ucp.payment_authorizer`):

```php
public function supports(string $handlerId): bool;
public function authorize(CheckoutCompleteRequest $request, PaymentInstrument $instrument, Cart $cart, SalesChannelContext $context, RequestContext $requestContext): PaymentAuthorizationResult;
```

`CheckoutCompleter` invokes the first authorizer whose `supports()` matches
`payment.instruments[0].handler_id` **before** `placeOrder()`. Return
`PaymentAuthorizationResult::authorized($authorizationId)` to proceed, or
`PaymentAuthorizationResult::failed($code, $message)` to abort — the code
surfaces as the 422 error code (e.g. `payment_declined`). If no authorizer
supports the handler, completion fails with `payment_handler_unsupported`; the
AP2 payment mandate arrives in `instrument->credential` (e.g. `token`).

### 3. UCP payment handler (instrument discovery/tokenization)

Ship a real `ucp_sdk.payment_handler` (tokenization,
`available_instruments`) as described in the payment handler guide; the bundled
invoice handler remains non-tokenizing and is not advertised.

### Reference implementations and acceptance tests

`src/Ucp/Test/Ap2/FixtureAp2MandateClaimsVerifier` and
`FixtureAp2PaymentAuthorizer` are minimal reference implementations (no real
cryptography; non-prod + `SWAG_AGENTIC_COMMERCE_TEST_AP2=1` only). A PSP
integration can validate its wiring against `tests/e2e/ucp/ap2-checkout.spec.js`
and `bin/ci-smoke.sh`: once its verifier/authorizer replace the fixtures, the
same flows (advertisement, `mandate_required`, `mandate_scope_mismatch`,
declined payment, successful completion) must hold with real mandates.
