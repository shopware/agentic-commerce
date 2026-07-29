<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutGuestAddressPayloadResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutPaymentNegotiator;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManager;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

final class ShopwareCheckoutAdapter implements CheckoutAdapterInterface
{
    public function __construct(
        private readonly ShopwareCartGateway $cartGateway,
        private readonly OrderGatewayInterface $orderGateway,
        private readonly ShopwareDataMapper $mapper,
        private readonly CheckoutSessionStore $sessionStore,
        private readonly CheckoutSessionManager $sessionManager,
        private readonly CheckoutCompletionStoreInterface $completionStore,
        private readonly CheckoutGuestAddressPayloadResolver $guestAddressPayloadResolver,
        private readonly CheckoutContinueUrlBuilder $continueUrlBuilder,
        private readonly CheckoutCompleter $checkoutCompleter,
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly ContextTokenGenerator $contextTokenGenerator,
        private readonly CheckoutPaymentNegotiator $paymentNegotiator,
    ) {
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        $cartId = $this->checkoutCartId($request);
        $token = $cartId ?? $this->contextTokenGenerator->generate();
        $discountCodes = $this->discountCodes($request->discounts);
        [$salesChannelContext, $cart] = $this->createOrReuseCheckoutCart($token, $cartId, $request, $discountCodes, $context);

        $status = $this->statusFor($cart->getLineItems()->count(), null !== $request->buyer);
        $this->sessionManager->save(
            $salesChannelContext,
            $status->value,
            $request->buyer,
            $discountCodes,
            guestAddress: $this->guestAddressPayloadResolver->resolve($request->fulfillment),
        );

        return $this->mapper->toCheckout(
            $cart,
            $salesChannelContext,
            $status,
            $request->buyer,
            $this->continueUrlBuilder->build($salesChannelContext->getToken(), $salesChannelContext->getSalesChannelId()),
        );
    }

    /**
     * @param list<string> $discountCodes
     *
     * @return array{0: SalesChannelContext, 1: Cart}
     */
    private function createOrReuseCheckoutCart(
        string $token,
        ?string $cartId,
        CheckoutCreateRequest $request,
        array $discountCodes,
        RequestContext $context,
    ): array {
        if ([] === $request->lineItems && null !== $cartId) {
            return $this->cartGateway->loadCheckoutCart($token, $context);
        }

        return $this->cartGateway->synchronizeCheckoutCart(
            $token,
            $request->lineItems,
            $discountCodes,
            $context,
        );
    }

    private function checkoutCartId(CheckoutCreateRequest $request): ?string
    {
        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($request);
        $cartId = $payload['cartId'] ?? null;

        return \is_string($cartId) && '' !== $cartId ? $cartId : null;
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);
        $metadata = $this->sessionStore->load($id, $resolution->salesChannelId);

        $completedOrderId = $this->completedOrderId($id, $metadata);
        if (null !== $completedOrderId) {
            $resolvedContext = $this->completedCheckoutContext($metadata, $context);
            $order = $this->orderGateway->getOrderForSalesChannelContext($completedOrderId, $resolvedContext, $metadata);

            return $this->completedCheckout($order, $id, $resolvedContext->getSalesChannelId());
        }

        $contextToken = $this->sessionStore->contextToken($metadata, $id);
        [$salesChannelContext, $cart] = $this->cartGateway->loadCheckoutCart($contextToken, $context);
        $buyer = $this->sessionStore->buyer($metadata);

        return $this->mapper->toCheckout(
            $cart,
            $salesChannelContext,
            $this->statusFor($cart->getLineItems()->count(), null !== $buyer),
            $buyer,
            $this->continueUrlBuilder->build($id, $salesChannelContext->getSalesChannelId()),
        );
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);
        $metadata = $this->sessionStore->load($request->id, $resolution->salesChannelId);

        if (($metadata['status'] ?? null) === 'completed' || null !== $this->completionStore->completedOrderId($request->id)) {
            throw new ValidationException('Completed checkout sessions cannot be updated.');
        }

        $discountCodes = $this->discountCodes($request->discounts);
        [$salesChannelContext, $cart] = $this->cartGateway->synchronizeCheckoutCart(
            $request->id,
            $request->lineItems,
            $discountCodes,
            $context,
        );

        $buyer = $request->buyer ?? $this->sessionStore->buyer($metadata);
        $status = $this->statusFor($cart->getLineItems()->count(), null !== $buyer);

        // Record the payment handler the client commits to (UCP PaymentInstrument),
        // preserving an earlier commitment when this update omits it. completeCheckout
        // uses it to decide whether it can settle or must hand off to a human.
        $paymentHandlerId = $request->payment?->handlerId ?? $this->sessionStore->paymentHandlerId($metadata);

        $this->sessionManager->save(
            $salesChannelContext,
            $status->value,
            $buyer,
            $discountCodes,
            guestAddress: $this->guestAddressPayloadResolver->resolve($request->fulfillment, $metadata),
            paymentHandlerId: $paymentHandlerId,
        );

        return $this->mapper->toCheckout(
            $cart,
            $salesChannelContext,
            $status,
            $buyer,
            $this->continueUrlBuilder->build($request->id, $salesChannelContext->getSalesChannelId()),
        );
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);
        $metadata = $this->sessionStore->load($id, $resolution->salesChannelId);

        $completedOrderId = $this->completedOrderId($id, $metadata);
        if (null !== $completedOrderId) {
            $resolvedContext = $this->completedCheckoutContext($metadata, $context);
            $order = $this->orderGateway->getOrderForSalesChannelContext($completedOrderId, $resolvedContext, $metadata);

            return $this->completedCheckout($order, $id, $resolvedContext->getSalesChannelId());
        }

        $contextToken = $this->sessionStore->contextToken($metadata, $id);
        [$salesChannelContext, $cart] = $this->cartGateway->loadCheckoutCart($contextToken, $context);

        // Opt-in payment-method negotiation (default off): when the channel requires a
        // committed payment method and the client committed none the shop can settle,
        // there is no mutually-agreed method — hand off to the browser checkout via
        // requires_escalation + continue_url and place NO order. Clients commit their
        // handler via CheckoutUpdateRequest.payment; off by default (payment optional).
        if ($this->paymentNegotiator->shouldEscalate($resolution->salesChannelId, $this->sessionStore->paymentHandlerId($metadata))) {
            return $this->mapper->toCheckout(
                $cart,
                $salesChannelContext,
                CheckoutStatus::RequiresEscalation,
                $this->sessionStore->buyer($metadata),
                $this->continueUrlBuilder->build($id, $salesChannelContext->getSalesChannelId()),
            );
        }

        return $this->checkoutCompleter->complete($id, $metadata, $cart, $salesChannelContext, $context);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function completedOrderId(string $checkoutId, array $metadata): ?string
    {
        $orderId = $this->completionStore->completedOrderId($checkoutId);
        if (null !== $orderId) {
            return $orderId;
        }

        if (($metadata['status'] ?? null) !== 'completed') {
            return null;
        }

        $legacyOrderId = $metadata['orderId'] ?? null;

        return \is_string($legacyOrderId) && '' !== $legacyOrderId ? $legacyOrderId : null;
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);
        $metadata = $this->sessionStore->load($id, $resolution->salesChannelId);
        $buyer = $this->sessionStore->buyer($metadata);

        [$salesChannelContext] = $this->cartGateway->loadCheckoutCart($id, $context);
        $cart = $this->cartGateway->cancelCart($id, $context);

        $this->sessionManager->save($salesChannelContext, CheckoutStatus::Canceled->value, $buyer);

        return $this->mapper->toCheckout(
            new Cart($cart->id),
            $salesChannelContext,
            CheckoutStatus::Canceled,
            $buyer,
            $this->continueUrlBuilder->build($id, $salesChannelContext->getSalesChannelId()),
        );
    }

    private function statusFor(int $lineItemCount, bool $hasBuyer): CheckoutStatus
    {
        if (0 === $lineItemCount) {
            return CheckoutStatus::Incomplete;
        }

        if (!$hasBuyer) {
            return CheckoutStatus::Incomplete;
        }

        return CheckoutStatus::ReadyForComplete;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function completedCheckoutContext(array $metadata, RequestContext $context): SalesChannelContext
    {
        $contextToken = $this->sessionStore->storedContextToken($metadata);
        if (null === $contextToken) {
            throw new ValidationException('Completed checkout session is missing its Shopware context token.');
        }

        return $this->contextResolver->resolve($contextToken, $context);
    }

    /**
     * @param list<object{code: string}> $discounts
     *
     * @return list<string>
     */
    private function discountCodes(array $discounts): array
    {
        return array_map(
            static fn ($discount): string => $discount->code,
            $discounts,
        );
    }

    private function completedCheckout(
        OrderEntity $order,
        string $checkoutId,
        string $salesChannelId,
    ): Checkout {
        return $this->mapper->toCompletedCheckout(
            $order,
            $checkoutId,
            $order->getCurrency()?->getIsoCode() ?? 'EUR',
            $this->continueUrlBuilder->build($checkoutId, $salesChannelId),
        );
    }
}
