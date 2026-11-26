<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentProcessor;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentResult;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisionerInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Buyer;
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

            $config = $this->configService->getConfig($customerContext->getSalesChannelId());
            if (null !== $config->webhookUrlOverride) {
                $this->webhookUrlGuard->assertAllowed($config->webhookUrlOverride, $config, $customerContext->getSalesChannelId());
            }

            $customerContext = $this->completionPayment->apply(
                $paymentInstrument,
                $customerContext,
                $requestContext,
            );

            $order = $this->orderGateway->placeOrder($cart, $customerContext, $paymentInstrument);
            $permalinkUrl = $this->orderPermalinkBuilder->build($order, $requestContext);
            $payment = $this->completionPayment->pay($order->getId(), $customerContext, $paymentInstrument, $permalinkUrl);

            // Saved before the completion row: a reader that finds the row loads the session afterwards
            // and needs its rotated context token.
            $this->saveSession($checkoutId, $customerContext, $buyer, $order, $payment);

            $this->completionStore->complete($checkoutId, $order->getId());

            if (null !== $config->webhookUrlOverride) {
                $this->orderWebhookPublisher->publish(
                    $config->webhookUrlOverride,
                    new OrderWebhookPayload(event: 'order.created', orderId: $order->getId(), payload: [
                        'order' => $this->mapper->toOrderView($order)->toArray(),
                    ]),
                    $requestContext,
                );
            }

            return $this->placedCheckout($checkoutId, $order, $customerContext, $permalinkUrl, $payment);
        } finally {
            $lock->release();
        }
    }

    /**
     * Pays the order of a checkout again after its payment failed.
     *
     * @param array<string, mixed> $metadata
     */
    public function retryPayment(
        string $checkoutId,
        array $metadata,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        RequestContext $requestContext,
        ?PaymentInstrument $paymentInstrument,
    ): Checkout {
        $lock = $this->lock($checkoutId, $salesChannelContext->getSalesChannelId());

        try {
            $permalinkUrl = $this->orderPermalinkBuilder->build($order, $requestContext);
            $payment = $this->completionPayment->retry($order->getId(), $paymentInstrument, $salesChannelContext, $requestContext, $permalinkUrl);

            $this->saveSession($checkoutId, $salesChannelContext, $this->sessionManager->buyer($metadata), $order, $payment);

            return $this->placedCheckout($checkoutId, $order, $salesChannelContext, $permalinkUrl, $payment);
        } finally {
            $lock->release();
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

    private function saveSession(string $checkoutId, SalesChannelContext $context, ?Buyer $buyer, OrderEntity $order, CompletionPaymentResult $payment): void
    {
        $this->sessionManager->saveForCheckoutId(
            $checkoutId,
            $context,
            $payment->status->value,
            $buyer,
            orderId: $order->getId(),
            orderDeepLinkCode: $order->getDeepLinkCode(),
            paymentRedirectUrl: $payment->redirectUrl,
        );
    }

    private function placedCheckout(string $checkoutId, OrderEntity $order, SalesChannelContext $context, string $permalinkUrl, CompletionPaymentResult $payment): Checkout
    {
        return $this->mapper->toCompletedCheckout(
            $order,
            $checkoutId,
            $context->getCurrency()->getIsoCode(),
            $this->continueUrlBuilder->build($checkoutId, $context->getSalesChannelId()),
            orderPermalinkUrl: $permalinkUrl,
            legalLinks: $this->legalLinkBuilder->build($context),
            payment: $payment,
        );
    }
}
