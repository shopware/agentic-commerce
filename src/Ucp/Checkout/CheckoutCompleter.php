<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentProcessor;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentResult;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PaymentFailedMessage;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisionerInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/** @internal */
#[Package('checkout')]
final class CheckoutCompleter
{
    public function __construct(
        private readonly OrderGatewayInterface $orderGateway,
        private readonly ShopwareDataMapperInterface $mapper,
        private readonly GuestCustomerContextProvisionerInterface $guestCustomerContextProvisioner,
        private readonly UcpConfigService $configService,
        private readonly CheckoutSessionManagerInterface $sessionManager,
        private readonly CheckoutCompletionStoreInterface $completionStore,
        private readonly LockFactory $lockFactory,
        private readonly CheckoutContinueUrlBuilderInterface $continueUrlBuilder,
        private readonly CheckoutWebhookUrlGuard $webhookUrlGuard,
        private readonly OrderWebhookPublisherInterface $orderWebhookPublisher,
        private readonly OrderPermalinkBuilder $orderPermalinkBuilder,
        private readonly CompletionPaymentProcessor $completionPayment,
        private readonly CheckoutLegalLinkBuilder $legalLinkBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return Checkout|null null when another request has completed this checkout; the caller replays it from the session
     */
    public function complete(
        string $checkoutId,
        array $metadata,
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        RequestContext $requestContext,
        CheckoutStatus $statusBeforeCompletion,
        ?PaymentInstrument $paymentInstrument = null,
    ): ?Checkout {
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        if (null !== $this->completionStore->completedOrderId($checkoutId)) {
            return null;
        }

        $lock = $this->lock($checkoutId, $salesChannelId);

        try {
            if (null !== $this->completionStore->completedOrderId($checkoutId)) {
                return null;
            }

            $buyer = $this->sessionManager->buyer($metadata);

            $customerContext = $this->guestCustomerContextProvisioner->ensureGuestCustomer(
                $salesChannelContext,
                $buyer,
                $this->sessionManager->guestAddress($metadata),
                $this->sessionManager->guestShippingAddress($metadata),
            );

            // Guest registration rotates the Shopware context token and migrates the persisted cart
            // along with it, so the token on the cart we were handed points at a storage entry that
            // no longer exists. Re-tokenize before ordering: the order route recalculates the cart
            // against the customer context anyway, but it rejects carts whose token it cannot find.
            $cart->setToken($customerContext->getToken());

            try {
                $salesChannelUcpConfig = $this->configService->getConfig($customerContext->getSalesChannelId());
                if (null !== $salesChannelUcpConfig->webhookUrlOverride) {
                    $this->webhookUrlGuard->assertAllowed($salesChannelUcpConfig->webhookUrlOverride, $salesChannelUcpConfig, $customerContext->getSalesChannelId());
                }

                try {
                    $customerContext = $this->completionPayment->apply(
                        $paymentInstrument,
                        $customerContext,
                        $requestContext,
                    );
                } catch (CompletionPaymentException|ValidationException $exception) {
                    return $this->refusedCheckout($checkoutId, $cart, $customerContext, $buyer, $metadata, $statusBeforeCompletion, $exception);
                }

                [$order, $paymentOutcome] = $this->placeOrder($cart, $customerContext, $paymentInstrument);
            } catch (\Throwable $exception) {
                $this->saveOpenSession($checkoutId, $customerContext, $buyer, $metadata, $statusBeforeCompletion);

                throw $exception;
            }

            $permalinkUrl = $this->orderPermalinkBuilder->build($order, $requestContext);

            // The order is tied to the checkout before the payment runs, so a crash in between cannot lose it.
            // The session comes first: a reader that finds the completion row needs its rotated context token.
            $this->saveSession($checkoutId, $customerContext, $buyer, $order, $paymentOutcome ?? CompletionPaymentResult::inProgress());
            $this->completionStore->complete($checkoutId, $order->getId());

            if (null === $paymentOutcome) {
                $paymentOutcome = $this->completionPayment->pay(
                    $order->getId(),
                    $customerContext,
                    $paymentInstrument,
                    $requestContext,
                    $permalinkUrl,
                    $this->errorUrl($checkoutId, $customerContext, $permalinkUrl),
                );
                $this->saveSession($checkoutId, $customerContext, $buyer, $order, $paymentOutcome);
            }

            if (null !== $salesChannelUcpConfig->webhookUrlOverride) {
                $this->orderWebhookPublisher->publish(
                    $salesChannelUcpConfig->webhookUrlOverride,
                    new OrderWebhookPayload(event: 'order.created', orderId: $order->getId(), payload: [
                        'order' => $this->mapper->toOrderView($order)->toArray(),
                    ]),
                    $requestContext,
                );
            }

            return $this->placedCheckout($checkoutId, $order, $customerContext, $permalinkUrl, $paymentOutcome);
        } finally {
            $lock->release();
        }
    }

    public function retryPayment(
        string $checkoutId,
        string $orderId,
        SalesChannelContext $salesChannelContext,
        RequestContext $requestContext,
        ?PaymentInstrument $paymentInstrument,
    ): Checkout {
        $lock = $this->lock($checkoutId, $salesChannelContext->getSalesChannelId());

        try {
            // Read again under the lock: another request may have paid the order in the meantime.
            $metadata = $this->sessionManager->load($checkoutId, $salesChannelContext->getSalesChannelId());
            $order = $this->orderGateway->getOrderForSalesChannelContext($orderId, $salesChannelContext, $metadata);
            $permalinkUrl = $this->orderPermalinkBuilder->build($order, $requestContext);
            $paymentOutcome = CompletionPaymentResult::ofPlacedOrder($metadata, $order);

            if ($paymentOutcome->canBePaidAgain()) {
                try {
                    $paymentOutcome = $this->completionPayment->retry(
                        $orderId,
                        $paymentInstrument,
                        $salesChannelContext,
                        $requestContext,
                        $permalinkUrl,
                        $this->errorUrl($checkoutId, $salesChannelContext, $permalinkUrl),
                    );
                } catch (CompletionPaymentException|ValidationException $exception) {
                    $refusal = PaymentFailedMessage::ofRefusal($exception);
                    if (PaymentFailedMessage::isUnrecoverable($refusal)) {
                        $paymentOutcome = CompletionPaymentResult::failed(false);
                        $this->saveSession($checkoutId, $salesChannelContext, $this->sessionManager->buyer($metadata), $order, $paymentOutcome);
                    }

                    return $this->placedCheckout($checkoutId, $order, $salesChannelContext, $permalinkUrl, $paymentOutcome, [$refusal]);
                }

                $this->saveSession($checkoutId, $salesChannelContext, $this->sessionManager->buyer($metadata), $order, $paymentOutcome);
            }

            return $this->placedCheckout($checkoutId, $order, $salesChannelContext, $permalinkUrl, $paymentOutcome);
        } finally {
            $lock->release();
        }
    }

    public function markReadyToPayAgain(
        string $checkoutId,
        string $orderId,
        SalesChannelContext $salesChannelContext,
        RequestContext $requestContext,
    ): Checkout {
        $lock = $this->lock($checkoutId, $salesChannelContext->getSalesChannelId());

        try {
            // Read again under the lock: another request may have paid the order in the meantime.
            $metadata = $this->sessionManager->load($checkoutId, $salesChannelContext->getSalesChannelId());
            $order = $this->orderGateway->getOrderForSalesChannelContext($orderId, $salesChannelContext, $metadata);
            $paymentOutcome = CompletionPaymentResult::ofPlacedOrder($metadata, $order);

            if ($paymentOutcome->canBePaidAgain()) {
                $paymentOutcome = CompletionPaymentResult::readyToPayAgain();
                $this->saveSession($checkoutId, $salesChannelContext, $this->sessionManager->buyer($metadata), $order, $paymentOutcome);
            }

            return $this->placedCheckout($checkoutId, $order, $salesChannelContext, $this->orderPermalinkBuilder->build($order, $requestContext), $paymentOutcome);
        } finally {
            $lock->release();
        }
    }

    public function markCannotBePaidAgain(
        string $checkoutId,
        string $orderId,
        SalesChannelContext $salesChannelContext,
        RequestContext $requestContext,
        Message $refusal,
    ): Checkout {
        $lock = $this->lock($checkoutId, $salesChannelContext->getSalesChannelId());

        try {
            // Read again under the lock: another request may have paid the order in the meantime.
            $metadata = $this->sessionManager->load($checkoutId, $salesChannelContext->getSalesChannelId());
            $order = $this->orderGateway->getOrderForSalesChannelContext($orderId, $salesChannelContext, $metadata);
            $paymentOutcome = CompletionPaymentResult::ofPlacedOrder($metadata, $order);
            $messages = null;

            if ($paymentOutcome->canBePaidAgain()) {
                $paymentOutcome = CompletionPaymentResult::failed(false);
                $messages = [$refusal];
                $this->saveSession($checkoutId, $salesChannelContext, $this->sessionManager->buyer($metadata), $order, $paymentOutcome);
            }

            return $this->placedCheckout($checkoutId, $order, $salesChannelContext, $this->orderPermalinkBuilder->build($order, $requestContext), $paymentOutcome, $messages);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{0: OrderEntity, 1: CompletionPaymentResult|null} the payment result is set when Shopware already ran the payment
     */
    private function placeOrder(Cart $cart, SalesChannelContext $context, ?PaymentInstrument $instrument): array
    {
        try {
            return [$this->orderGateway->placeOrder($cart, $context, $instrument), null];
        } catch (CartException $exception) {
            $orderId = $exception->getParameters()['orderId'] ?? null;
            if (CartException::CART_PAYMENT_INVALID_ORDER_STORED_CODE !== $exception->getErrorCode() || !\is_string($orderId)) {
                throw $exception;
            }

            // Shopware 6.5 and 6.6 capture a prepared payment inside the order route and keep the order when that fails.
            // Their payment processing skips a prepared handler, so paying again would report success without a capture.
            return [$this->orderGateway->getOrderForSalesChannelContext($orderId, $context), CompletionPaymentResult::failed(false)];
        }
    }

    private function lock(string $checkoutId, string $salesChannelId): LockInterface
    {
        $lock = $this->lockFactory->createLock(
            'ucp.checkout.completion.'.$checkoutId.'.'.$salesChannelId,
            300.0,
        );

        if (!$lock->acquire(false)) {
            throw new ValidationException('Checkout completion is already processing; retry the same checkout id after the in-flight request finishes.');
        }

        return $lock;
    }

    private function saveSession(string $checkoutId, SalesChannelContext $context, ?Buyer $buyer, OrderEntity $order, CompletionPaymentResult $paymentOutcome): void
    {
        $this->sessionManager->saveForCheckoutId(
            $checkoutId,
            $context,
            $paymentOutcome->status->value,
            $buyer,
            orderId: $order->getId(),
            orderDeepLinkCode: $order->getDeepLinkCode(),
            payment: $paymentOutcome,
        );
    }

    /**
     * @param list<Message>|null $messages
     */
    private function placedCheckout(string $checkoutId, OrderEntity $order, SalesChannelContext $context, string $permalinkUrl, CompletionPaymentResult $paymentOutcome, ?array $messages = null): Checkout
    {
        return $this->mapper->toCompletedCheckout(
            $order,
            $checkoutId,
            $context->getCurrency()->getIsoCode(),
            $this->continueUrlBuilder->build($checkoutId, $context->getSalesChannelId()),
            orderPermalinkUrl: $permalinkUrl,
            legalLinks: $this->legalLinkBuilder->build($context),
            payment: $paymentOutcome,
            messages: $messages,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function refusedCheckout(string $checkoutId, Cart $cart, SalesChannelContext $context, ?Buyer $buyer, array $metadata, CheckoutStatus $status, CompletionPaymentException|ValidationException $exception): Checkout
    {
        $this->saveOpenSession($checkoutId, $context, $buyer, $metadata, $status);
        $cart->setToken($checkoutId);

        return $this->mapper->toCheckout(
            $cart,
            $context,
            $status,
            $buyer,
            $this->continueUrlBuilder->build($checkoutId, $context->getSalesChannelId()),
            legalLinks: $this->legalLinkBuilder->build($context),
            messages: [PaymentFailedMessage::recoverable($exception->getMessage())],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function saveOpenSession(string $checkoutId, SalesChannelContext $context, ?Buyer $buyer, array $metadata, CheckoutStatus $status): void
    {
        // Registering the guest rotated the context token and moved the cart with it, so the session has to follow.
        $this->sessionManager->saveForCheckoutId(
            $checkoutId,
            $context,
            $status->value,
            $buyer,
            guestAddress: $this->sessionManager->guestAddress($metadata),
            guestShippingAddress: $this->sessionManager->guestShippingAddress($metadata),
        );
    }

    private function errorUrl(string $checkoutId, SalesChannelContext $context, string $permalinkUrl): string
    {
        return $this->continueUrlBuilder->build($checkoutId, $context->getSalesChannelId()) ?? $permalinkUrl;
    }
}
