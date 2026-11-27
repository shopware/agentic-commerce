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
 * Returns the context with the payment method switched, so switching the method and recalculating happen
 * in one call. Refusing is a first-class outcome: throw `Ucp\Sdk\Exception\ValidationException`
 * rather than falling back to the default silently.
 *
 * Runs on Create and Update Checkout, as the check on Update after a failed payment, on Complete Checkout
 * before the order is placed, and when Complete Checkout pays a placed order again. It can run several
 * times per checkout, so it must not reserve or authorise the instrument.
 *
 * An abstract class, not an interface, per `adr/2020-11-25-decoration-pattern.md`.
 *
 * @see docs/completion-payment.md for the full contract, including which instrument you get
 */
#[Package('framework')]
abstract class AbstractCompletionPaymentApplier
{
    /**
     * The request key under which the payment handler of the order's payment method finds the instrument.
     */
    public const REQUEST_KEY = 'ucpPaymentInstrument';

    abstract public function getDecorated(): self;

    /**
     * @param PaymentInstrument|null $instrument the one instrument the request carries
     * @param SalesChannelContext    $context    the context of the checkout, or of its placed order when the payment is checked or paid again
     *
     * @return SalesChannelContext the context with the payment method of the instrument
     */
    abstract public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext;
}
