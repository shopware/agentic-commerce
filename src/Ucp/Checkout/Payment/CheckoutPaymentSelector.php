<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\RequestContext;

/**
 * Checks the instrument of a checkout request against the profile, and switches the payment method of the
 * context to the one the instrument names.
 *
 * @internal
 */
#[Package('checkout')]
final class CheckoutPaymentSelector
{
    public function __construct(
        private readonly CompletionPaymentGate $gate,
        private readonly AbstractCompletionPaymentApplier $applier,
        private readonly RegisteredHandlerPaymentMethodResolver $paymentMethodResolver,
    ) {
    }

    public function refusal(?PaymentInstrument $instrument, RequestContext $requestContext): ?Message
    {
        return $this->gate->refusal($instrument?->handlerId, $requestContext);
    }

    public function refusalForPlacedOrder(?PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext): ?Message
    {
        if (null === $instrument) {
            return PaymentFailedMessage::recoverable('Send the payment instrument to pay the order again.', '$.payment.instruments');
        }

        $paymentRefusal = $this->refusal($instrument, $requestContext);
        if (null !== $paymentRefusal) {
            return $paymentRefusal;
        }

        try {
            $this->paymentMethodResolver->resolve($instrument, $context, $requestContext);
        } catch (CompletionPaymentException|ValidationException $exception) {
            return PaymentFailedMessage::recoverable($exception->getMessage());
        }

        return null;
    }

    public function select(PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext): SalesChannelContext|Message
    {
        $paymentRefusal = $this->refusal($instrument, $requestContext);
        if (null !== $paymentRefusal) {
            return $paymentRefusal;
        }

        try {
            return $this->applier->apply($instrument, $context, $requestContext);
        } catch (CompletionPaymentException|ValidationException $exception) {
            return PaymentFailedMessage::recoverable($exception->getMessage());
        }
    }
}
