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
 * Returns the context the order is placed with, so switching the method and recalculating happen
 * in one call. Refusing is a first-class outcome: throw `Ucp\Sdk\Exception\ValidationException`
 * rather than falling back to the default silently.
 *
 * An abstract class, not an interface, per `adr/2020-11-25-decoration-pattern.md`.
 *
 * @see docs/completion-payment.md for the full contract, including which instrument you get
 */
#[Package('framework')]
abstract class AbstractCompletionPaymentApplier
{
    abstract public function getDecorated(): self;

    /**
     * @param PaymentInstrument|null $instrument the first instrument sent, not the chosen one
     * @param SalesChannelContext    $context    the context the order is about to be placed with
     *
     * @return SalesChannelContext the context to place it with
     */
    abstract public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext;
}
