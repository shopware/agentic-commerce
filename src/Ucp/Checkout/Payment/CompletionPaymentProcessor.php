<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
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
        private readonly AbstractContextSwitchRoute $contextSwitchRoute,
        private readonly PlacedOrderPaymentMethodGate $placedOrderGate,
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
            // The token outlives the refusal: a repeated completion would otherwise see equal totals and place the order.
            $this->contextSwitchRoute->switchContext(
                new RequestDataBag([SalesChannelContextService::PAYMENT_METHOD_ID => $context->getPaymentMethod()->getId()]),
                $switchedContext,
            );

            throw CompletionPaymentException::paymentMethodChangesTotal();
        }

        return $switchedContext;
    }

    public function pay(string $orderId, SalesChannelContext $context, ?PaymentInstrument $instrument, RequestContext $requestContext, string $finishUrl, string $errorUrl): CompletionPaymentResult
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

            return $this->failed($orderId, $context, $requestContext);
        }

        return null === $redirectUrl ? CompletionPaymentResult::completed() : CompletionPaymentResult::awaitsBuyer($redirectUrl);
    }

    /**
     * Pays an order again whose payment failed, with the payment method the applier picks for the new instrument.
     *
     * @throws CompletionPaymentException when the applier refuses, or when core would refuse its payment method for a placed order
     * @throws ValidationException
     */
    public function retry(string $orderId, ?PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext, string $finishUrl, string $errorUrl): CompletionPaymentResult
    {
        $context = $this->applier->apply($instrument, $context, $requestContext);
        $this->placedOrderGate->assertAllowed($context->getPaymentMethod(), $context->getSalesChannelId(), $requestContext);

        try {
            $this->paymentGateway->changePaymentMethod($orderId, $context);
        } catch (\Throwable $exception) {
            // Shopware refuses a payment method that is not available to the order, where another handler may still pay.
            $this->logger?->warning('Shopware refused to change the payment method of an order placed through a UCP checkout.', [
                'order_id' => $orderId,
                'payment_method_id' => $context->getPaymentMethod()->getId(),
                'sales_channel_id' => $context->getSalesChannelId(),
                'exception' => $exception,
            ]);

            return $this->failed($orderId, $context, $requestContext);
        }

        return $this->pay($orderId, $context, $instrument, $requestContext, $finishUrl, $errorUrl);
    }

    private function failed(string $orderId, SalesChannelContext $context, RequestContext $requestContext): CompletionPaymentResult
    {
        try {
            return CompletionPaymentResult::failed($this->placedOrderGate->canBePaidByAdvertisedHandler($context->getSalesChannelId(), $requestContext));
        } catch (\Throwable $exception) {
            // The order exists already, so a failed decision must not hide the failed payment from the agent.
            $this->logger?->warning('Whether an advertised payment handler can pay an order placed through a UCP checkout again could not be decided.', [
                'order_id' => $orderId,
                'sales_channel_id' => $context->getSalesChannelId(),
                'exception' => $exception,
            ]);

            return CompletionPaymentResult::failed();
        }
    }

    private function cartTotal(SalesChannelContext $context): float
    {
        return $this->cartLoadRoute->load(new Request(['token' => $context->getToken()]), $context)->getCart()->getPrice()->getTotalPrice();
    }
}
