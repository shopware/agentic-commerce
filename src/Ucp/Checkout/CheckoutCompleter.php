<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisionerInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Symfony\Component\Lock\LockFactory;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

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
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function complete(
        string $checkoutId,
        array $metadata,
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        RequestContext $requestContext,
    ): Checkout {
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        $orderId = $this->completionStore->completedOrderId($checkoutId, $salesChannelId);
        if (null !== $orderId) {
            return $this->replayCompletedOrder($orderId, $checkoutId, $salesChannelId, $requestContext);
        }

        $lock = $this->lockFactory->createLock(
            'ucp.checkout.completion.'.$checkoutId.'.'.$salesChannelId,
            300.0,
        );

        if (!$lock->acquire(false)) {
            throw new ValidationException('Checkout completion is already processing; retry the same checkout id after the in-flight request finishes.');
        }

        try {
            $orderId = $this->completionStore->completedOrderId($checkoutId, $salesChannelId);
            if (null !== $orderId) {
                return $this->replayCompletedOrder($orderId, $checkoutId, $salesChannelId, $requestContext);
            }

            $buyer = $this->sessionManager->buyer($metadata);

            $customerContext = $this->guestCustomerContextProvisioner->ensureGuestCustomer(
                $salesChannelContext,
                $buyer,
                $this->sessionManager->guestAddress($metadata),
            );

            $config = $this->configService->getConfig($customerContext->getSalesChannelId());
            if (null !== $config->webhookUrlOverride) {
                $this->webhookUrlGuard->assertAllowed($config->webhookUrlOverride, $config, $customerContext->getSalesChannelId());
            }

            $order = $this->orderGateway->placeOrder($cart, $customerContext);

            $this->completionStore->complete($checkoutId, $customerContext->getSalesChannelId(), $order->getId());

            $this->sessionManager->save(
                $customerContext,
                CheckoutStatus::Completed->value,
                $buyer,
                orderId: $order->getId(),
            );

            if (null !== $config->webhookUrlOverride) {
                $this->orderWebhookPublisher->publish(
                    $config->webhookUrlOverride,
                    new OrderWebhookPayload('order.created', $order->getId(), [
                        'order' => $this->mapper->toOrderView($order)->toArray(),
                    ]),
                    $requestContext,
                );
            }

            return $this->mapper->toCompletedCheckout(
                $order,
                $checkoutId,
                $customerContext->getCurrency()->getIsoCode(),
                $this->continueUrlBuilder->build($checkoutId, $customerContext->getSalesChannelId()),
            );
        } finally {
            $lock->release();
        }
    }

    private function replayCompletedOrder(
        string $orderId,
        string $checkoutId,
        string $salesChannelId,
        RequestContext $requestContext,
    ): Checkout {
        $order = $this->orderGateway->getOrder($orderId, $requestContext);

        return $this->mapper->toCompletedCheckout(
            $order,
            $checkoutId,
            $order->getCurrency()?->getIsoCode() ?? 'EUR',
            $this->continueUrlBuilder->build($checkoutId, $salesChannelId),
        );
    }
}
