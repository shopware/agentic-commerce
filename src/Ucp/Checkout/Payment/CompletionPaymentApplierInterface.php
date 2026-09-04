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
 * UCP marks `payment` as required on the complete operation, and this plugin has always
 * validated it and then thrown it away: completion charges whatever the sales channel
 * defaults to, no matter what the agent presented. That is not a small gap -- it is the
 * difference between a protocol that can carry a payment and one that only appears to.
 *
 * Closing it is deliberately not this plugin's decision to make alone. Selecting what a
 * buyer is charged with belongs to whoever owns checkout, and mapping an instrument onto a
 * concrete method belongs to a payment provider. So the plugin supplies the seam, the
 * instrument and the context, and stops there.
 *
 * An implementation receives the context the order is about to be placed with -- the guest
 * customer is provisioned, the cart is calculated -- and returns the context to place it
 * with. Switching the payment method, recalculating, and refusing an instrument it cannot
 * honour all happen inside that call, because they are one decision.
 *
 * Refusing is a first-class outcome. Throw a `Ucp\Sdk\Exception\ValidationException` for an
 * instrument this business cannot accept; do not fall back to the default silently, because
 * that is the behaviour that made this gap invisible for as long as it has been here.
 *
 * @see docs/completion-payment.md for what an implementation has to cover
 */
#[Package('framework')]
interface CompletionPaymentApplierInterface
{
    /**
     * @param PaymentInstrument|null $instrument the instrument the agent sent, when it sent one
     *
     * @return SalesChannelContext the context the order will be placed with
     */
    public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext;
}
