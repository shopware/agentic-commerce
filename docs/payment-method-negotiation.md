# Payment-method negotiation & checkout escalation

## Summary

Place an order on `completeCheckout` **only when the client committed a payment
method this shop can settle**. When there is no mutually-supported payment method,
return the checkout as **`requires_escalation`** with a **`continue_url`** and place
**no order** — the UCP-standard fallback that lets a human finish in a browser.

This is a deterministic, spec-conformant capability negotiation that works for **any**
UCP client (ChatGPT, an A2A harness, a custom agent). The decision lives in the shop
and is driven entirely by standard UCP fields.

## Motivation

x402 (and similar) settle out-of-band **after** the order is placed, so at completion
time the shop sees no payment either way — it cannot distinguish *"this client will pay
shortly"* from *"this client can't pay at all"*. Without a signal, a client that
supports none of the advertised `payment_handlers` either gets a placed, never-payable
order, or no path forward.

UCP already provides the pieces: the client commits a handler via
`CheckoutUpdateRequest.payment` (`PaymentInstrument`), the shop advertises
`payment_handlers`, and `continue_url` + the `requires_escalation` status are the
fallback. This change wires them together.

## Opt-in / non-breaking

Gated behind a per-channel policy `SwagAgenticCommerce.config.requireCommittedPaymentMethod`
(**default off**):

- **off (default):** unchanged behaviour — completion proceeds without a committed
  payment method (spec-conformant: the UCP `payment` object is optional).
- **on:** completion requires a committed, settle-able handler; otherwise it escalates.

The flag is currently read via `SystemConfigService` and set per channel with
`bin/console system:config:set SwagAgenticCommerce.config.requireCommittedPaymentMethod 1
--sales-channel=...`. Exposing it as an admin toggle (config.xml) is an easy follow-up.

## Behaviour (policy on)

1. On **update**, the committed `payment.handler_id` is stored in the checkout session
   (preserved across updates; latest commitment wins).
2. On **complete**, before placing an order:
   - committed handler resolves in the SDK `PaymentHandlerRegistry` → complete as today;
   - otherwise (no commitment, or an unsupported handler) → return the checkout with
     `status = requires_escalation` + `continue_url`, and place **no order**.

The registry lookup means no payment scheme is hard-coded — any registered handler
(x402, invoice, future ones) is accepted automatically. The decision is driven by what
the client **agrees to pay with**, not by what the shop offers, so the shop never
silently places an order (e.g. an unpaid invoice order) against a method the agent did
not choose.

## Changes

- `Ucp/Checkout/CheckoutSessionStore.php` — `paymentHandlerId()` reader.
- `Ucp/Checkout/CheckoutSessionManager.php` — persist the committed handler in metadata.
- `Ucp/Checkout/CheckoutPaymentNegotiator.php` (new) — the decision: reads the
  `requireCommittedPaymentMethod` policy and matches the committed handler against the
  SDK `PaymentHandlerRegistry`. Single-responsibility and unit-testable in isolation.
- `Ucp/Adapter/ShopwareCheckoutAdapter.php` — capture the commitment on update
  (`CheckoutUpdateRequest.payment`); delegate the escalate-vs-complete decision to
  `CheckoutPaymentNegotiator`, returning `ShopwareDataMapper::toCheckout(...,
  RequiresEscalation, ..., continueUrl)` when it says escalate.
- `tests/Unit/CheckoutPaymentNegotiatorTest.php` (new) — policy off never escalates;
  policy on escalates when no / an unsupported handler is committed, and completes when
  a registered handler is committed.

## Contract note

With the policy **on**, payment becomes an **explicit commitment**: a client that
commits no supported handler is escalated instead of getting a placed order. Clients
that complete with no payment instrument (relying on out-of-band settlement) must send a
`PaymentInstrument` (e.g. the x402 handler id) on update. With the policy **off**
(default) nothing changes, so this is opt-in per channel rather than a global behaviour
change.

## Follow-ups

- `continue_url` can point at any merchant checkout page. A signed, cart-adopting
  handoff route is one implementation but is deployment-specific and intentionally not
  part of this change.
- If a per-channel/per-request handler policy is wanted, the negotiation could read the
  advertised handler set from the profile instead of the global registry.
