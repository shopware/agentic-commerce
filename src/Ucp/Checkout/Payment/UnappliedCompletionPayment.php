<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * The default: change nothing, and say so.
 *
 * Completion keeps charging the sales channel default, which is what it did before this
 * seam existed, so installing this release changes no order. What changes is that the gap
 * is now audible -- an agent presenting an instrument produces a warning naming the handler
 * it asked for, instead of the instrument disappearing without trace.
 *
 * Deliberately a warning rather than a refusal. Refusing would be the more correct answer to
 * "we cannot honour this payment", and it is the answer a real implementation should give --
 * but making it the default would break every business already completing checkouts through
 * the channel default, for a capability they have not been offered yet. The refusal belongs
 * to whoever implements the real applier, and the interface says so.
 *
 * Replace by registering a service under this interface; there is nothing to unregister.
 *
 * @internal
 */
#[Package('framework')]
final class UnappliedCompletionPayment implements CompletionPaymentApplierInterface
{
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext {
        if (null === $instrument) {
            return $context;
        }

        $this->logger?->warning(
            'A UCP checkout was completed with the sales channel default payment method, ignoring the instrument the agent presented. '
            .'Register a service under '.CompletionPaymentApplierInterface::class.' to act on it; see docs/completion-payment.md.',
            [
                'handler_id' => $instrument->handlerId,
                'instrument_type' => $instrument->type,
                'sales_channel_id' => $context->getSalesChannelId(),
            ],
        );

        return $context;
    }
}
