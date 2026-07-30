<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateOrderPersister;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisionerInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizerRegistry;
use Symfony\Component\Lock\LockFactory;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\Ap2Exception;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/** @internal */
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
        private readonly PaymentAuthorizerRegistry $paymentAuthorizerRegistry = new PaymentAuthorizerRegistry(),
        private readonly ?Ap2MandateOrderPersister $mandateOrderPersister = null,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function complete(
        CheckoutCompleteRequest $request,
        array $metadata,
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        RequestContext $requestContext,
    ): Checkout {
        $checkoutId = $request->id;
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        $orderId = $this->completionStore->completedOrderId($checkoutId);
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
            $orderId = $this->completionStore->completedOrderId($checkoutId);
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

            // Fall back to the instrument selected during checkout.update so an
            // instrument-less complete request cannot skip PSP authorization.
            $paymentInstrument = $request->payment?->instruments[0]
                ?? $this->sessionManager->selectedPaymentInstrument($metadata);
            if (null !== $paymentInstrument) {
                $result = $this->paymentAuthorizerRegistry->authorize($request, $paymentInstrument, $cart, $customerContext, $requestContext);
                if (!$result->authorized) {
                    throw new Ap2Exception($result->failureCode ?? 'payment_authorization_failed', $result->failureMessage ?? 'Payment authorization failed.');
                }
            }

            $order = $this->orderGateway->placeOrder($cart, $customerContext);

            $this->completionStore->complete($checkoutId, $order->getId());

            // Store the verified AP2 mandate as dispute evidence on the order.
            $this->mandateOrderPersister?->persist($checkoutId, $order->getId(), $customerContext->getContext());

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
                $this->orderPermalinkUrl($order, $requestContext),
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
            $this->orderPermalinkUrl($order, $requestContext),
        );
    }

    /**
     * A placed order has no relation to the checkout id the continue-URL
     * template is built from, so its permalink instead uses the order's own
     * guest-access deepLinkCode against the storefront's order-detail-by-code
     * route (frontend.account.order.single.page) - the same guest-order proof
     * X402CheckoutResponseAugmenter already relies on for the pay route.
     */
    private function orderPermalinkUrl(OrderEntity $order, RequestContext $requestContext): ?string
    {
        $deepLinkCode = $order->getDeepLinkCode();
        $baseUri = rtrim($requestContext->runtimeConfiguration?->baseUri ?? '', '/');
        if (null === $deepLinkCode || '' === $deepLinkCode || '' === $baseUri) {
            return null;
        }

        return $baseUri.'/account/order/'.rawurlencode($deepLinkCode);
    }
}
