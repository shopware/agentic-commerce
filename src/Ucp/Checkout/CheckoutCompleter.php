<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisioner;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
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
        private readonly ShopwareDataMapper $mapper,
        private readonly GuestCustomerContextProvisioner $guestCustomerContextProvisioner,
        private readonly UcpConfigService $configService,
        private readonly CheckoutSessionManager $sessionManager,
        private readonly CheckoutCompletionStoreInterface $completionStore,
        private readonly CheckoutContinueUrlBuilder $continueUrlBuilder,
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
        $reservation = $this->completionStore->reserve($checkoutId, $salesChannelId);

        if (CheckoutCompletionReservationStatus::Completed === $reservation->status && null !== $reservation->orderId) {
            $order = $this->orderGateway->getOrder($reservation->orderId, $requestContext);

            return $this->mapper->toCompletedCheckout(
                $order,
                $checkoutId,
                $order->getCurrency()?->getIsoCode() ?? 'EUR',
                $this->continueUrlBuilder->build($checkoutId, $salesChannelId),
            );
        }

        if (CheckoutCompletionReservationStatus::Processing === $reservation->status) {
            throw new ValidationException('Checkout completion is already processing; retry the same checkout id after the in-flight request finishes.');
        }

        $buyer = $this->sessionManager->buyer($metadata);

        try {
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
        } catch (\Throwable $exception) {
            $this->completionStore->release($checkoutId, $salesChannelId);

            throw $exception;
        }

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
    }
}
