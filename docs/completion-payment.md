# Acting on the completion payment

## What happens today

An agent completing a UCP checkout sends a payment instrument. The specification marks it
required — `checkout.json` annotates `payment` as `ucp_request: {complete: "required"}` — and
this plugin validates it against the schema and then **ignores it**. Every UCP order is
placed with whatever payment method the sales channel defaults to, usually invoice or another
offline method.

That is not a rounding error. It is the difference between a protocol that can carry a
payment and one that only appears to.

Since the release that added this document, the instrument reaches a seam instead of being
dropped, and the default implementation logs a warning naming the handler the agent asked
for. **Order behaviour is unchanged** — the default still charges the channel default — so
installing the release changes nothing about how money moves. What changed is that the gap is
now audible and has one obvious place to close it.

## Why it is not closed here

Two decisions belong to people who are not this plugin:

- **What a buyer is charged with** is checkout's call, not an integration layer's.
- **How an instrument maps onto a concrete payment method** is a payment provider's, and
  differs per provider.

A plausible-looking implementation that guesses either one is worse than no implementation,
because it moves money. So this plugin supplies the instrument, the context and the timing,
and stops.

## The seam

```php
namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

interface CompletionPaymentApplierInterface
{
    public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext;
}
```

Register a service under that interface and it replaces the default:

```php
$services->set(AcmeCompletionPaymentApplier::class);
$services->alias(CompletionPaymentApplierInterface::class, AcmeCompletionPaymentApplier::class);
```

There is nothing to unregister and no compiler pass to write.

### When it is called

Immediately before the order is placed, and after everything the order needs already exists:

1. the checkout session is loaded and the cart is calculated,
2. the guest customer is provisioned and its context resolved,
3. **`apply()` runs**,
4. `placeOrder()` is called with the context `apply()` returned.

So an implementation may switch the payment method, recalculate, and return the new context —
those are one decision and one call.

It runs inside the completion lock, so it will not race a second completion of the same
checkout. It runs **before** `completionStore->complete()`, so throwing leaves the checkout
un-completed and the agent may retry.

### What it receives

`$instrument` is the instrument the agent selected. `payment.json` models the field as
`{"instruments": [...]}`, so a request carries a list; the plugin picks the one flagged
`selected` and otherwise the first, matching what the SDK does when reading the same shape on
create and update. It is `null` when the agent sent none — return the context unchanged.

`$instrument->handlerId` names a payment handler this business published in its profile.
`$instrument->credential` is an open map whose shape the handler defines.

## What an implementation has to cover

### 1. Resolve the instrument to a payment method

The plugin already has half of this. `PaymentHandlerInterface::prepareInstrument()` exists and
`ShopwareInvoicePaymentHandler` implements it, returning a `paymentMethodId` read from
`$instrument->credential['payment_method_id']` — and **nothing has ever called it**. Resolving
the handler through `PaymentHandlerRegistryInterface::find($instrument->handlerId)` and calling
`prepareInstrument()` is the intended path.

### 2. Switch the sales channel context

Switching the payment method on a `SalesChannelContext` and recalculating the cart is ordinary
Shopware work, and it is the part that needs checkout's agreement rather than an opinion from
here. Whatever it does must leave a context `placeOrder()` can use.

### 3. Refuse what cannot be honoured — do not fall back

Throw `Ucp\Sdk\Exception\ValidationException` when:

- the handler id names a handler this business does not publish,
- the credential does not carry what the handler needs,
- the resolved payment method is not available on this sales channel,
- the provider declines.

The SDK maps that to a UCP error descriptor the agent can read and act on.

**Falling back to the channel default is the behaviour that made this gap invisible for as
long as it has been here.** An agent that presents a card and receives a successful order has
been told its payment was accepted. Silence is the wrong answer to "I cannot charge this".

The one exception is the default implementation shipped with the plugin, which deliberately
does fall back and warns instead — because making refusal the default would break every
business already completing checkouts through the channel default, for a capability they have
not been offered yet.

### 4. Decide what tokenization means for you

`ShopwareInvoicePaymentHandler` reports `tokenization: false` and returns `null` from
`tokenize()`. A provider handling real credentials will want the opposite, and the tokenization
capability this plugin publishes (`dev.ucp.shopping.payment_tokenization`) is an id no UCP
release defines — at `2026-08-25` tokenization is a payment *handler* concern
(`handlers/tokenization/openapi.json`), not a shopping capability. Worth settling before
building against it.

## Testing an implementation

The acceptance test is an order placed against a **non-default** payment method, driven end to
end over HTTP: create a checkout, complete it with an instrument naming that method, and assert
the resulting order carries it. A unit test asserting `apply()` returns a switched context
proves the seam and not the outcome.

`UnappliedCompletionPayment` is a usable stand-in for tests that need the seam to do nothing.

## Open questions for the checkout team and the provider

1. Which payment methods should be reachable through UCP at all — everything the sales channel
   offers, or an allow-list?
2. Should an unavailable method fail the completion, or fail the earlier `checkout.update` that
   selected it, so the agent learns sooner?
3. Does a declined payment leave the checkout retryable, or move it to a terminal state?
4. Who owns the mapping from a UCP handler id to a Shopware payment method — configuration, the
   handler implementation, or the provider's own registry?
