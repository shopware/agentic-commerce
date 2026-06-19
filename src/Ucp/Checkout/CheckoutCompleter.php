<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisioner;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

final class CheckoutCompleter
{
    public function __construct(
        private readonly ShopwareOrderGateway $orderGateway,
        private readonly ShopwareDataMapper $mapper,
        private readonly GuestCustomerContextProvisioner $guestCustomerContextProvisioner,
        private readonly UcpConfigService $configService,
        private readonly CheckoutSessionManager $sessionManager,
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

        $this->sessionManager->saveForCheckoutId(
            $checkoutId,
            $customerContext,
            CheckoutStatus::Completed->value,
            $buyer,
            orderId: $order->getId(),
            orderDeepLinkCode: $order->getDeepLinkCode(),
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
