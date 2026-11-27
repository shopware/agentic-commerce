# Acting on the completion payment

## What happens today

An agent completing a UCP checkout sends a payment instrument. The specification marks it required —
`checkout.json` annotates `payment` as `ucp_request: {complete: "required"}` — and the plugin
validates it against the schema.

The UCP SDK refuses a completion that does not carry exactly one instrument, before the plugin is
called. Then `CompletionPaymentGate` checks the instrument. A completion is refused unless its
`handler_id` is a payment handler the business profile advertises in `payment_handlers` for the
sales channel. The gate builds the profile from the same runtime configuration `/.well-known/ucp`
uses, so what an agent could read there is what the gate accepts, and a handler a plugin has
registered but the profile does not list is refused.

A refusal is a business outcome. It is answered with `200`, the checkout keeps the status it has
(`ready_for_complete` for a checkout with line items and a buyer), and it carries one message of
`type: error`, `code: payment_failed` and `severity: recoverable`. The SDK's refusal of the count
has no `path`. The gate's refusal has `$.payment.instruments[0].handler_id` when the handler is not
advertised. No order is placed, no guest customer is registered and the session is not written. The
SDK stores the refusal under the request's `Idempotency-Key` like any other answer, so an agent
retries with a new key. Every gate refusal is also logged as a warning with the handler id and the
sales channel's tenant identifier, so a merchant can see why agents fail to complete.

A completion without a UCP runtime configuration is not refused: the gate cannot tell which handlers
the business advertises, so it throws `UcpConfigException::runtimeConfigurationMissing()`, which is
answered with `500`. The dry run of the `complete_checkout` MCP tool reports it as a blocker.

The legacy `completeCheckout($id, $context)` of the checkout adapter carries no instrument and is
refused with `payment_failed`, so an extension that decorates or calls it cannot bypass the gate.
The dry run of the `complete_checkout` MCP tool never reaches the SDK's count check, so it reports a
count other than one itself.

## Which handlers the profile advertises

The profile lists every payment handler registered with the `ucp_sdk.payment_handler` tag as soon as
UCP is active for the sales channel. The plugin registers three handlers for Shopware's offline
payment methods:

| Handler id                      | Payment method of the sales channel whose handler is |
| ------------------------------- | ---------------------------------------------------- |
| `com.shopware.invoice`          | `InvoicePayment`                                     |
| `com.shopware.prepayment`       | `PrePayment`                                         |
| `com.shopware.cash_on_delivery` | `CashPayment`                                        |

A handler is advertised only on a sales channel that has an active payment method with that core
handler assigned. The payment method is resolved on the server; a `payment_method_id` in the agent's
credential is ignored. A sales channel without any of these methods advertises only the handlers
that payment extensions register.

The `ucp.payment_handlers` the SDK negotiates for a checkout keeps only the handlers the profile
advertises for the sales channel. Up to SDK `0.0.10` a successful checkout response carries
`payment_handlers` as an empty map regardless of the negotiation.

## Selecting the payment method before completion

An instrument on Create Checkout or Update Checkout switches the payment method of the checkout
already, so the totals the agent shows the buyer include what that method changes, such as a
surcharge or a rule that depends on it. The instrument passes the same gate and the same applier as
at completion. A refusal keeps the checkout as it was and adds one recoverable `payment_failed`
message, whether the applier refuses with `CompletionPaymentException` or with the
`Ucp\Sdk\Exception\ValidationException` a replacement applier throws.

At completion the applier runs again. When the payment method it switches to changes the total of
the cart, the completion is refused with a recoverable `payment_failed` asking the agent to update
the checkout with this instrument first, and no order is placed. Without a change of the total, the
completion goes on.

## What happens to an instrument that passes the gate

1. `AbstractCompletionPaymentApplier::apply()` decides which payment method the order gets. The
   default implementation, `RegisteredHandlerCompletionPayment`, finds the registered handler of the
   instrument, takes the payment method its `prepareInstrument()` returns, checks that the buyer may
   pay with it, and switches the context to it. It never falls back to the sales channel's default
   payment method.
2. The order is placed through the Store API order route. The instrument is part of the request
   data, so the payment handler of the order's payment method can refuse it before the order is
   written. See
   [Receiving the instrument in a payment handler](#receiving-the-instrument-in-a-payment-handler).
3. The plugin runs Shopware's payment handling for the order through the Store API route behind
   `/store-api/handle-payment`, the call a headless storefront makes after placing an order. The
   instrument is part of that request as well. The finish URL is the order page and the error URL is
   the checkout's `continue_url`, or the order page when the checkout has none.
4. The outcome is mapped to the checkout:

| Payment handling                 | Checkout `status`     | What the agent gets                                                                         |
| -------------------------------- | --------------------- | ------------------------------------------------------------------------------------------- |
| returns without a redirect       | `completed`           | `order` with its `permalink_url`                                                            |
| returns a redirect               | `requires_escalation` | the redirect as `continue_url` and a `payment_requires_buyer_input` message, no `order` yet |
| throws, or answers its error URL | `incomplete`          | a recoverable `payment_failed` message at `$.payment.instruments[0]`, no `order`            |

A refusal of the applier before the order is placed is answered like a gate refusal: the checkout
keeps its status, with one recoverable `payment_failed` message and no order. The guest customer
registered for the completion stays.

While the payment call runs, `checkout.get` reads `complete_in_progress`.

`completed` means that nothing is left for the agent to do. It does not mean that money arrived: an
invoice or prepayment order is `completed` with an open transaction, as it is in the Storefront.

### After a redirect

The buyer finishes the payment at `continue_url` and returns to the order page of the Storefront.
Until then `checkout.get` and a repeated `checkout.complete` answer `requires_escalation` with the
same `continue_url`, and no second payment is started. Once the order transaction leaves the states
`open`, `in_progress`, `unconfirmed` and `reminded`, the checkout reads `completed`. When it becomes
`failed` or `cancelled`, the checkout reads `incomplete` with `payment_failed`.

### After a failed payment

The order exists and its cart is gone. The checkout reads `incomplete` with a recoverable
`payment_failed` message at `$.payment.instruments[0]`, so the agent resolves it through Update
Checkout, as the UCP specification describes for `incomplete`.

An Update Checkout with an instrument for another payment handler is accepted when the order can be
paid again. The instrument has to pass the gate and the applier, as on completion. The default
applier already switches the payment method of the checkout, while the order keeps its payment until
the agent completes the checkout. The checkout then reads `ready_for_complete` without a message,
and `checkout.get` answers the same until the agent completes it. An Update Checkout without an
instrument, or with one that is refused, keeps the status of the checkout and answers one
recoverable `payment_failed` message, and the order keeps its payment.

The order cannot change, so only its payment can. An Update Checkout whose line items differ from
the order's in product or quantity keeps the status as well and answers one recoverable error with
the code `order_placed` at `$.line_items`. Line items that repeat the order's, or none, are
accepted. Buyer, fulfillment and discounts of the request are ignored. The specification states no
error for a change the business cannot apply, but it answers an Update Checkout during
`complete_in_progress` with the current checkout and a recoverable message, and this follows that. A
placed checkout that cannot be paid again, because its payment is paid, awaits the buyer or failed
for good, still answers an Update Checkout with `422`.

The agent then completes the checkout, with the same instrument or another one. That call passes the
gate and the applier like the first one. It then gives the order a new transaction for the payment
method of the returned context, through the Store API route behind `/store-api/order/payment`, and
runs the payment handling again. No second order is placed. The handler's `validate()` does not run
again, because no order is placed. A completion that is refused keeps the status the checkout had.

Newer Shopware versions, from `6.6.10.21` and `6.7.13.0` on, only change the payment of an order
when the payment method has _Allow payment change after checkout_ switched on. Payment methods that
extensions add have it off by default. When Shopware refuses the change, the answer is `incomplete`
with a recoverable `payment_failed` message. The agent can then complete the checkout with the
instrument of another handler. A payment extension that wants agents to pay again with its own
payment method switches the setting on for it.

When the order is paid in another way in the meantime, for example by the buyer on the order page of
the Storefront, the checkout reads `completed`.

Every failed payment is logged as a warning with the order id, the handler id, the payment method id
and the exception. The plugin does not log the credential itself and does not store it in the
checkout session. An exception message that a payment handler writes ends up in the log, so a
handler must not put the token into it.

### What is not covered yet

- A buyer who never finishes at the payment page leaves the checkout at `requires_escalation`. The
  agent has no call that replaces the open payment.
- `checkout.cancel` refuses a checkout whose order is placed, so the agent cannot cancel that order.
- A refusal in the handler's `validate()` reaches the agent as an error response, not as
  `payment_failed`.
- On Shopware `6.5` and `6.6`, a prepared payment handler that fails in `capture()` leaves an order
  behind. The checkout then reads `incomplete` with an `unrecoverable` `payment_failed`, because
  completing again would not capture.
- The handler list is per sales channel. A handler whose payment method an availability rule blocks
  for this buyer is advertised, and refused when it is selected.
- The `com.shopware.*` handlers have no published specification or schema pages yet.

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

A payment extension usually needs neither. Registering an SDK payment handler whose
`prepareInstrument()` returns the `paymentMethodId` of its payment method is enough: the default
applier switches to that method.

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

        // ... switch the payment method and return the context to place with
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

The only one. The SDK refuses a completion with zero or several instruments before the applier is
reached, so `$instrument` is always the single instrument the agent sent. On create and update it is
the instrument the SDK selected: the one marked `selected`, else the first one.

### When it is called

On Create Checkout and Update Checkout, when the request carries an instrument that passes the gate.

At completion, immediately before the order is placed, and after everything the order needs already
exists:

1. the checkout session is loaded and the cart is calculated,
2. `CompletionPaymentGate` accepts the instrument,
3. the guest customer is provisioned and its context resolved,
4. **`apply()` runs**, and the plugin compares the total of the cart before and after it,
5. `placeOrder()` is called with the context `apply()` returned,
6. the payment handling runs for the placed order, with the same context.

`apply()` also runs when the agent completes a checkout again after a failed payment. The order
exists then, and the payment method of the returned context becomes the order's new payment method.

The gate sits before guest provisioning on purpose: a completion the gate refuses registers no
customer.

It runs inside the completion lock, so it will not race a second completion of the same checkout. It
runs **before** `completionStore->complete()`, so throwing leaves the checkout un-completed and the
agent may retry.

### What it receives

`$instrument` is the one instrument the agent sent. The type allows `null` for an applier that is
called from elsewhere, and the checkout adapter never passes it, because a completion without an
instrument is refused before this point.

`$instrument->handlerId` names a payment handler this business advertises in its profile.
`$instrument->credential` is an open map whose shape the handler defines.

### Refusing what cannot be honoured

Do not fall back to the sales channel's default payment method. An agent that presents a card and
receives a successful order has been told its payment was accepted. Throw
`Ucp\Sdk\Exception\ValidationException` when the credential does not carry what the handler needs,
or when the resolved payment method cannot be used. The plugin answers it with a recoverable
`payment_failed` message that carries the message of the exception, and the checkout keeps its
status. That holds on Create Checkout, Update Checkout, Complete Checkout and when the agent
completes again after a failed payment. A refusal from the payment handler itself, in `validate()`
or `pay()`, needs no special exception.

Return the context you switched. On Create Checkout and Update Checkout the plugin loads the cart
again with the context `apply()` returns, so the totals follow the payment method of that context.

## Receiving the instrument in a payment handler

The applier only picks the payment method. The token reaches the payment extension through the
handler of that payment method, under the request key
`AbstractCompletionPaymentApplier::REQUEST_KEY` (`ucpPaymentInstrument`):

```php
[
    'handlerId' => 'com.acme.card',
    'type' => 'card',
    'credential' => ['type' => 'token', 'token' => '...'],
    'billingAddress' => [...],
]
```

| Shopware version    | Before the order is written                                     | After the order is placed                                 |
| ------------------- | --------------------------------------------------------------- | --------------------------------------------------------- |
| `6.6.5.0` and newer | `AbstractPaymentHandler::validate()` reads it from the data bag | `AbstractPaymentHandler::pay()` reads it from the request |
| older               | a prepared payment handler reads it in `validate()`             | `pay()` reads it from the `RequestDataBag`                |

In `validate()` and in `pay()` on older versions the value is a nested data bag: read it with
`$dataBag->get('ucpPaymentInstrument')?->all()`. In `pay()` on `6.6.5.0` and newer it is a plain
array: `$request->request->all('ucpPaymentInstrument')`.

A refusal in `validate()` stops the completion before an order exists, but the agent gets an error
response and not `payment_failed`. A failure in `pay()` leaves an order with a failed transaction
behind, which the agent pays again as described above. Do not return the token from `validate()`:
Shopware stores the returned struct unencrypted on the order transaction.

An existing payment handler does not look at this key, so it treats an agent order like a Storefront
order without payment data. A handler that needs the buyer in the browser returns its redirect, and
the agent gets `requires_escalation`.

## Tokenization

The offline handlers report `tokenization: false` and return `null` from `tokenize()`. A provider
handling real credentials will want the opposite, and the tokenization capability id of this plugin
(`dev.ucp.shopping.payment_tokenization`) is one no UCP release defines, and the profile does not
publish it. At `2026-08-25` tokenization is a payment _handler_ concern
(`handlers/tokenization/openapi.json`), not a shopping capability. Worth settling before building
against it.

## Testing an implementation

The acceptance test is an order placed against a **non-default** payment method, driven end to end
over HTTP: create a checkout, complete it with an instrument naming that method, and assert the
resulting order carries it and that its transaction has the state your handler sets. A unit test
asserting `apply()` returns a switched context proves the seam and not the outcome.

## Open questions

1. Should merchants be able to keep payment methods away from agents with an allow-list, or is
   everything the sales channel offers the right set?
2. Who publishes the specification and schema pages for the `com.shopware.*` handlers?
