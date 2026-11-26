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
is refused. The `ucp.payment_handlers` of a checkout response comes from the SDK, which fills it
from every registered handler the agent's profile also names.

A refusal is a business outcome. It is answered with `200`, the checkout stays `incomplete`, and it
carries one message of `type: error`, `code: payment_failed` and `severity: recoverable`. Its `path`
is `$.payment.instruments` when the count is not one and `$.payment.instruments[0].handler_id` when
the handler is not advertised. No order is placed, no guest customer is registered and the session
is not written. The SDK stores the refusal under the request's `Idempotency-Key` like any other
answer, so an agent retries with a new key. Every refusal is also logged as a warning with the
handler ids and the sales channel's tenant identifier, so a merchant can see why agents fail to
complete.

A completion with one instrument but without a UCP runtime configuration is not refused: the gate
cannot tell which handlers the business advertises, so it throws
`UcpConfigException::runtimeConfigurationMissing()`, which is answered with `500`. The dry run of
the `complete_checkout` MCP tool reports it as a blocker.

The legacy `completeCheckout($id, $context)` of the checkout adapter carries no instrument and
counts as zero instruments, so an extension that decorates or calls it cannot bypass the gate. The
dry run of the `complete_checkout` MCP tool applies the same rule.

The profile lists every payment handler registered with the `ucp_sdk.payment_handler` tag as soon as
UCP is active for the sales channel. The plugin registers `com.shopware.invoice` itself, so every
UCP shop advertises at least that one.

## What happens to an instrument that passes the gate

1. `AbstractCompletionPaymentApplier::apply()` decides which payment method the order gets. The
   default implementation below keeps the sales channel's default payment method and logs a warning
   naming the handler.
2. The order is placed through the Store API order route. The instrument is part of the request
   data, so the payment handler of the order's payment method can refuse it before the order is
   written. See
   [Receiving the instrument in a payment handler](#receiving-the-instrument-in-a-payment-handler).
3. The plugin runs Shopware's payment handling for the order through the Store API route behind
   `/store-api/handle-payment`, the call a headless storefront makes after placing an order. The
   instrument is part of that request as well.
4. The outcome is mapped to the checkout:

| Payment handling                 | Checkout `status`     | What the agent gets                                                                         |
| -------------------------------- | --------------------- | ------------------------------------------------------------------------------------------- |
| returns without a redirect       | `completed`           | `order` with its `permalink_url`                                                            |
| returns a redirect               | `requires_escalation` | the redirect as `continue_url` and a `payment_requires_buyer_input` message, no `order` yet |
| throws, or answers its error URL | `incomplete`          | a recoverable `payment_failed` message at `$.payment.instruments[0]`, no `order`            |

`completed` means that nothing is left for the agent to do. It does not mean that money arrived: an
invoice or prepayment order is `completed` with an open transaction, as it is in the Storefront.

### After a redirect

The buyer finishes the payment at `continue_url` and returns to the order page of the Storefront.
Until then `checkout.get` and a repeated `checkout.complete` answer `requires_escalation` with the
same `continue_url`, and no second payment is started. Once the order transaction leaves the states
`open`, `in_progress` and `unconfirmed`, the checkout reads `completed`. When it becomes `failed` or
`cancelled`, the checkout reads `incomplete` with `payment_failed`.

### After a failed payment

The order exists and its cart is gone, so the checkout can no longer be updated. The agent completes
the checkout again, with the same or another instrument. That call passes the gate and the applier
like the first one. It then gives the order a new transaction for the payment method of the returned
context, through the Store API route behind `/store-api/order/payment`, and runs the payment
handling again. No second order is placed. When Shopware does not allow the order's payment to
change any more, the call is answered with an error and nothing is charged.

Every failed payment is logged as a warning with the order id, the handler id and the payment method
id. The credential is never logged and never stored in the checkout session.

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
5. `placeOrder()` is called with the context `apply()` returned,
6. the payment handling runs for the placed order, with the same context.

`apply()` also runs when the agent completes a checkout again after a failed payment. The order
exists then, and the payment method of the returned context becomes the order's new payment method.

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

## Receiving the instrument in a payment handler

The applier only picks the payment method. The token reaches the payment extension through the
handler of that payment method, under the request key
`AbstractCompletionPaymentApplier::REQUEST_KEY` (`ucpPaymentInstrument`):

```php
[
    'type' => 'card',
    'handler_id' => 'com.acme.card',
    'credential' => ['type' => 'token', 'token' => '...'],
    'billing_address' => [...],
]
```

| Shopware version    | Before the order is written                                     | After the order is placed                                 |
| ------------------- | --------------------------------------------------------------- | --------------------------------------------------------- |
| `6.6.5.0` and newer | `AbstractPaymentHandler::validate()` reads it from the data bag | `AbstractPaymentHandler::pay()` reads it from the request |
| older               | a prepared payment handler reads it in `validate()`             | `pay()` reads it from the `RequestDataBag`                |

Refuse a token you cannot charge in `validate()` where you can: the completion then fails before an
order exists. A failure in `pay()` leaves an order with a failed transaction behind, which the agent
pays again as described above.

An existing payment handler does not look at this key, so it treats an agent order like a Storefront
order without payment data. A handler that needs the buyer in the browser returns its redirect, and
the agent gets `requires_escalation`.

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

The SDK maps that to a UCP error descriptor the agent can read and act on. A refusal from the
payment handler itself, in `validate()` or `pay()`, needs no special exception.

**Falling back to the channel default is the behaviour that made this gap invisible for as long as
it has been here.** An agent that presents a card and receives a successful order has been told its
payment was accepted. Silence is the wrong answer to "I cannot charge this".

The one exception is the default implementation shipped with the plugin, which deliberately does
fall back and warns instead. The payment handling then runs for the sales channel's default payment
method, so a method that needs the buyer still answers `requires_escalation`.

### 4. Decide what tokenization means for you

`ShopwareInvoicePaymentHandler` reports `tokenization: false` and returns `null` from `tokenize()`.
A provider handling real credentials will want the opposite, and the tokenization capability this
plugin publishes (`dev.ucp.shopping.payment_tokenization`) is an id no UCP release defines — at
`2026-08-25` tokenization is a payment _handler_ concern (`handlers/tokenization/openapi.json`), not
a shopping capability. Worth settling before building against it.

## Testing an implementation

The acceptance test is an order placed against a **non-default** payment method, driven end to end
over HTTP: create a checkout, complete it with an instrument naming that method, and assert the
resulting order carries it and that its transaction has the state your handler sets. A unit test
asserting `apply()` returns a switched context proves the seam and not the outcome.

`UnappliedCompletionPayment` is a usable stand-in for tests that need the seam to do nothing.

## Open questions for the checkout team and the provider

1. Which payment methods should be reachable through UCP at all — everything the sales channel
   offers, or an allow-list?
2. Should an unavailable method fail the completion, or fail the earlier `checkout.update` that
   selected it, so the agent learns sooner?
3. Who owns the mapping from a UCP handler id to a Shopware payment method — configuration, the
   handler implementation, or the provider's own registry?
