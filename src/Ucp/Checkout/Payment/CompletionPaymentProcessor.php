<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Gateway\OrderPaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * Runs Shopware's payment handling for the order of a completion.
 *
 * @see docs/completion-payment.md
 *
 * @internal
 */
#[Package('checkout')]
final class CompletionPaymentProcessor
{
    public function __construct(
        private readonly AbstractCompletionPaymentApplier $applier,
        private readonly OrderPaymentGatewayInterface $paymentGateway,
        private readonly AbstractCartLoadRoute $cartLoadRoute,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws CompletionPaymentException when the applier refuses, or when its payment method changes the total the agent last saw
     * @throws ValidationException
     */
    public function apply(?PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext): SalesChannelContext
    {
        $totalBeforeSwitch = $this->cartTotal($context);
        $switchedContext = $this->applier->apply($instrument, $context, $requestContext);

        if ($this->cartTotal($switchedContext) !== $totalBeforeSwitch) {
            throw CompletionPaymentException::paymentMethodChangesTotal();
        }

        return $switchedContext;
    }

    public function pay(string $orderId, SalesChannelContext $context, ?PaymentInstrument $instrument, string $finishUrl, string $errorUrl): CompletionPaymentResult
    {
        try {
            $redirectUrl = $this->paymentGateway->pay($orderId, $context, $instrument, $finishUrl, $errorUrl);
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
     */
    public function retry(string $orderId, ?PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext, string $finishUrl, string $errorUrl): CompletionPaymentResult
    {
        $context = $this->applier->apply($instrument, $context, $requestContext);

        try {
            $this->paymentGateway->changePaymentMethod($orderId, $context);
        } catch (\Throwable $exception) {
            // Shopware refuses the change for a payment method without "Allow payment change after checkout".
            $this->logger?->warning('An order placed through a UCP checkout cannot be paid again: Shopware refused to change its payment.', [
                'order_id' => $orderId,
                'payment_method_id' => $context->getPaymentMethod()->getId(),
                'sales_channel_id' => $context->getSalesChannelId(),
                'exception' => $exception,
            ]);

            return CompletionPaymentResult::failed(false);
        }

        return $this->pay($orderId, $context, $instrument, $finishUrl, $errorUrl);
    }

    private function cartTotal(SalesChannelContext $context): float
    {
        return $this->cartLoadRoute->load(new Request(['token' => $context->getToken()]), $context)->getCart()->getPrice()->getTotalPrice();
    }
}
