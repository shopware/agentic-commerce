# Acting on the completion payment

## What happens today

An agent completing a UCP checkout sends a payment instrument. The specification marks it required —
`checkout.json` annotates `payment` as `ucp_request: {complete: "required"}` — and the plugin
validates it against the schema.

Before the instrument reaches anything that places an order, `CompletionPaymentGate` checks it. A
completion is refused unless it carries **exactly one** instrument whose `handler_id` is a payment
handler the business profile advertises in `payment_handlers` for the sales channel. The gate builds
the profile from the same runtime configuration `/.well-known/ucp` uses, so what an agent could read
there is what the gate accepts, and a handler a plugin has registered but the profile does not list
is refused. The `ucp.payment_handlers` of a checkout response can list more: the SDK fills it from
every registered handler the agent's profile also names, so it can name a handler the gate refuses
until the profile lists the registered handlers.

A refusal is a business outcome. It is answered with `200`, the checkout stays `incomplete`, and it
carries one message of `type: error`, `code: payment_failed` and `severity: recoverable`. Its `path`
is `$.payment.instruments` when the count is not one and `$.payment.instruments[0].handler_id` when
the handler is not advertised. No order is placed, no guest customer is registered and the session
is not written. The SDK stores the refusal under the request's `Idempotency-Key` like any other
answer, so an agent retries with a new key.

The legacy `completeCheckout($id, $context)` of the checkout adapter carries no instrument and
counts as zero instruments, so an extension that decorates or calls it cannot bypass the gate. The
dry run of the `complete_checkout` MCP tool applies the same rule.

The profile advertises no payment handler yet, so no UCP checkout can be completed until it does.
The exception is a shop where an extension registers a tokenizing payment handler and payment
tokenization is switched on: the profile then lists every registered handler. Listing the handlers
in every shop is tracked in
[shopware/agentic-commerce#279](https://github.com/shopware/agentic-commerce/issues/279).

What stays open is what happens to an instrument that passes the gate. The default implementation
below still places the order with the sales channel's default payment method and logs a warning
naming the handler. A real applier closes that, and the rest of this document is about writing one.

## Why it is not closed here

Two decisions belong to people who are not this plugin:

- **What a buyer is charged with** is checkout's call, not an integration layer's.
- **How an instrument maps onto a concrete payment method** is a payment provider's, and differs per
  provider.

A plausible-looking implementation that guesses either one is worse than no implementation, because
it moves money. So this plugin supplies the instrument, the context and the timing, and stops.

## The seam

```php
namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

abstract class AbstractCompletionPaymentApplier
{
    abstract public function getDecorated(): self;

    abstract public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext;
}
```

An abstract class rather than an interface, following the platform's decoration ADR
(`adr/2020-11-25-decoration-pattern.md`), so you can either replace the default or decorate it.

**Replace it** — yours is the only applier, and its `getDecorated()` throws:

```php
$services->set(AcmeCompletionPaymentApplier::class);
$services->alias(AbstractCompletionPaymentApplier::class, AcmeCompletionPaymentApplier::class);
```

**Decorate it** — act on the instruments you know, and let the one you wrapped have the rest:

```php
final class AcmeCompletionPaymentApplier extends AbstractCompletionPaymentApplier
{
    public function __construct(private readonly AbstractCompletionPaymentApplier $decorated)
    {
    }

    public function getDecorated(): AbstractCompletionPaymentApplier
    {
        return $this->decorated;
    }

    public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext {
        if ('com.acme.card' !== $instrument?->handlerId) {
            return $this->getDecorated()->apply($instrument, $context, $requestContext);
        }

        // ... switch the payment method, recalculate, and return the context to place with
    }
}
```

```php
$services->set(AcmeCompletionPaymentApplier::class)
    ->decorate(AbstractCompletionPaymentApplier::class)
    ->args([service('.inner')]);
```

There is nothing to unregister and no compiler pass to write.

### Which instrument you get

The only one. The gate refuses a completion with zero or several instruments before the applier is
reached, so `$instrument` is always the single instrument the agent sent.

UCP models payment as `{"instruments": [...]}` and marks the buyer's choice with `selected` at the
instrument top level. The plugin never has to choose between instruments, which is why it does not
need `selected`. The SDK's `PaymentInstrument` carries the flag, on completion too, since 0.0.9.

### When it is called

Immediately before the order is placed, and after everything the order needs already exists:

1. the checkout session is loaded and the cart is calculated,
2. `CompletionPaymentGate` accepts the instrument,
3. the guest customer is provisioned and its context resolved,
4. **`apply()` runs**,
5. `placeOrder()` is called with the context `apply()` returned.

The gate sits before guest provisioning on purpose: a refused completion registers no customer.

So an implementation may switch the payment method, recalculate, and return the new context — those
are one decision and one call.

It runs inside the completion lock, so it will not race a second completion of the same checkout. It
runs **before** `completionStore->complete()`, so throwing leaves the checkout un-completed and the
agent may retry.

### What it receives

`$instrument` is the one instrument the agent sent. The type allows `null` for an applier that is
called from elsewhere, and the checkout adapter never passes it, because a completion without an
instrument is refused before this point.

`$instrument->handlerId` names a payment handler this business advertises in its profile.
`$instrument->credential` is an open map whose shape the handler defines.

## What an implementation has to cover

### 1. Resolve the instrument to a payment method

The plugin already has half of this. `PaymentHandlerInterface::prepareInstrument()` exists and
`ShopwareInvoicePaymentHandler` implements it, returning a `paymentMethodId` read from
`$instrument->credential['payment_method_id']` — and **nothing has ever called it**. Resolving the
handler through `PaymentHandlerRegistryInterface::find($instrument->handlerId)` and calling
`prepareInstrument()` is the intended path.

### 2. Switch the sales channel context

Switching the payment method on a `SalesChannelContext` and recalculating the cart is ordinary
Shopware work, and it is the part that needs checkout's agreement rather than an opinion from here.
Whatever it does must leave a context `placeOrder()` can use.

### 3. Refuse what cannot be honoured — do not fall back

The gate has already refused a handler id this business does not advertise. Throw
`Ucp\Sdk\Exception\ValidationException` when:

- the credential does not carry what the handler needs,
- the resolved payment method is not available on this sales channel,
- the provider declines.

The SDK maps that to a UCP error descriptor the agent can read and act on.

**Falling back to the channel default is the behaviour that made this gap invisible for as long as
it has been here.** An agent that presents a card and receives a successful order has been told its
payment was accepted. Silence is the wrong answer to "I cannot charge this".

The one exception is the default implementation shipped with the plugin, which deliberately does
fall back and warns instead. It only sees a handler the profile advertises, and no payment provider
ships a handler together with its applier yet.

### 4. Decide what tokenization means for you

`ShopwareInvoicePaymentHandler` reports `tokenization: false` and returns `null` from `tokenize()`.
A provider handling real credentials will want the opposite, and the tokenization capability this
plugin publishes (`dev.ucp.shopping.payment_tokenization`) is an id no UCP release defines — at
`2026-08-25` tokenization is a payment _handler_ concern (`handlers/tokenization/openapi.json`), not
a shopping capability. Worth settling before building against it.

## Testing an implementation

The acceptance test is an order placed against a **non-default** payment method, driven end to end
over HTTP: create a checkout, complete it with an instrument naming that method, and assert the
resulting order carries it. A unit test asserting `apply()` returns a switched context proves the
seam and not the outcome.

`UnappliedCompletionPayment` is a usable stand-in for tests that need the seam to do nothing.

## Open questions for the checkout team and the provider

1. Which payment methods should be reachable through UCP at all — everything the sales channel
   offers, or an allow-list?
2. Should an unavailable method fail the completion, or fail the earlier `checkout.update` that
   selected it, so the agent learns sooner?
3. Does a declined payment leave the checkout retryable, or move it to a terminal state?
4. Who owns the mapping from a UCP handler id to a Shopware payment method — configuration, the
   handler implementation, or the provider's own registry?
