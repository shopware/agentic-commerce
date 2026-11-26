<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Gateway\OrderPaymentGatewayInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * Runs Shopware's payment handling for the order of a completion.
 *
 * @see docs/completion-payment.md
 *
 * @internal
 */
final class CompletionPaymentProcessor
{
    public function __construct(
        private readonly AbstractCompletionPaymentApplier $applier,
        private readonly OrderPaymentGatewayInterface $paymentGateway,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function apply(?PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext): SalesChannelContext
    {
        return $this->applier->apply($instrument, $context, $requestContext);
    }

    public function pay(string $orderId, SalesChannelContext $context, ?PaymentInstrument $instrument, string $returnUrl): CompletionPaymentResult
    {
        try {
            $redirectUrl = $this->paymentGateway->pay($orderId, $context, $instrument, $returnUrl);
        } catch (\Throwable $exception) {
            // The order exists already, so the failure is answered as a checkout the agent can pay again.
            $this->logger?->warning('The payment of an order placed through a UCP checkout failed.', [
                'order_id' => $orderId,
                'handler_id' => $instrument?->handlerId,
                'payment_method_id' => $context->getPaymentMethod()->getId(),
                'sales_channel_id' => $context->getSalesChannelId(),
                'exception' => $exception,
            ]);

            return CompletionPaymentResult::failed();
        }

        return null === $redirectUrl ? CompletionPaymentResult::completed() : CompletionPaymentResult::awaitsBuyer($redirectUrl);
    }

    /**
     * Pays an order again whose payment failed, with the payment method the applier picks for the new instrument.
     *
     * @throws \Throwable when the instrument is refused or the order's payment can no longer change; nothing was charged then
     */
    public function retry(string $orderId, ?PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext, string $returnUrl): CompletionPaymentResult
    {
        $context = $this->applier->apply($instrument, $context, $requestContext);
        $this->paymentGateway->changePaymentMethod($orderId, $context);

        return $this->pay($orderId, $context, $instrument, $returnUrl);
    }
}
