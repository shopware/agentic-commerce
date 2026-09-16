<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * The seam where an agent's payment instrument becomes a Shopware payment.
 *
 * Selecting what a buyer is charged with belongs to whoever owns checkout, and mapping an
 * instrument onto a concrete method belongs to a payment provider. So the plugin supplies the
 * seam, the instrument and the context, and stops there.
 *
 * An implementation receives the context the order is about to be placed with -- the guest
 * customer is provisioned, the cart is calculated -- and returns the context to place it with.
 * Switching the payment method, recalculating, and refusing an instrument it cannot honour all
 * happen inside that call, because they are one decision.
 *
 * Refusing is a first-class outcome. Throw a `Ucp\Sdk\Exception\ValidationException` for an
 * instrument this business cannot accept; do not fall back to the default silently.
 *
 * An abstract class rather than an interface, per the platform's decoration ADR
 * (`adr/2020-11-25-decoration-pattern.md`): a partner can decorate the shipped default -- act on
 * the instruments it knows, delegate the rest to `getDecorated()` -- as well as replace it, and
 * a parameter can be added here later without breaking every implementation at once.
 *
 * @see docs/completion-payment.md for what an implementation has to cover
 */
#[Package('framework')]
abstract class AbstractCompletionPaymentApplier
{
    abstract public function getDecorated(): self;

    /**
     * @param PaymentInstrument|null $instrument the *first* instrument on the completion, when
     *                                           there was one -- not necessarily the one the buyer
     *                                           chose. UCP marks the choice with `selected` at the
     *                                           instrument top level; the SDK's `PaymentInstrument`
     *                                           has no such property, so on completion nothing
     *                                           reaches this plugin that could tell two instruments
     *                                           apart. Refuse rather than guess if that matters to
     *                                           you. Tracked in
     *                                           agentic-commerce-alliance/ucp-php-sdk#190
     *
     * @return SalesChannelContext the context the order will be placed with
     */
    abstract public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext;
}
