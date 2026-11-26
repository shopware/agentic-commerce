<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PaymentFailedMessage;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\RegisteredHandlerPaymentMethodResolver;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\RequestContext;

/**
 * The payment refusals the dry run of a completion reports, read without placing an order or switching the
 * payment method of the checkout.
 *
 * The total-change refusal at completion is not predicted, because that needs the switch.
 *
 * @internal
 */
#[Package('checkout')]
final class UcpCheckoutCompletionPaymentCheck
{
    public function __construct(
        private readonly CompletionPaymentGate $gate,
        private readonly RegisteredHandlerPaymentMethodResolver $paymentMethodResolver,
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly CheckoutSessionStore $sessionStore,
    ) {
    }

    /**
     * The dry run never reaches the executor of the SDK, which refuses a completion without exactly one instrument.
     *
     * @param list<PaymentInstrument> $instruments
     */
    public function refusal(array $instruments, mixed $status, string $checkoutId, RequestContext $requestContext): ?Message
    {
        if (1 !== \count($instruments)) {
            return PaymentFailedMessage::recoverable('Complete Checkout requires exactly one payment instrument.', '$.payment.instruments');
        }

        try {
            $gateRefusal = $this->gate->refusal($instruments[0]->handlerId, $requestContext);
        } catch (UcpConfigException $exception) {
            return new Message('error', $exception->getMessage(), 'unrecoverable', $exception->getErrorCode());
        }

        // A completed checkout is replayed before the payment gate runs.
        if (null !== $gateRefusal || \in_array($status, [CheckoutStatus::Completed, CheckoutStatus::Completed->value], true)) {
            return $gateRefusal;
        }

        try {
            $this->paymentMethodResolver->resolve($instruments[0], $this->checkoutContext($checkoutId, $requestContext), $requestContext);
        } catch (CompletionPaymentException|ValidationException $exception) {
            return PaymentFailedMessage::recoverable($exception->getMessage());
        }

        return null;
    }

    private function checkoutContext(string $checkoutId, RequestContext $requestContext): SalesChannelContext
    {
        $sessionMetadata = $this->sessionStore->load($checkoutId, $this->contextResolver->resolveSalesChannel($requestContext)->salesChannelId);

        return $this->contextResolver->resolve($this->sessionStore->contextToken($sessionMetadata, $checkoutId), $requestContext);
    }
}
