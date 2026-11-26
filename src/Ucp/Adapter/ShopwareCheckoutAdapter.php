<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutGuestAddressPayloadResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutLegalLinkBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManager;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CheckoutPaymentSelector;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentResult;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem as UcpLineItem;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[Package('checkout')]
final class ShopwareCheckoutAdapter implements PaymentAwareCheckoutAdapterInterface
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
        private readonly OrderPermalinkBuilder $orderPermalinkBuilder,
        private readonly CheckoutLegalLinkBuilder $legalLinkBuilder,
        private readonly CheckoutPaymentSelector $paymentSelector,
    ) {
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        $cartId = $this->checkoutCartId($request);
        $token = $cartId ?? $this->contextTokenGenerator->generate();
        $discountCodes = $this->discountCodes($request->discounts);
        [$salesChannelContext, $cart] = $this->createOrReuseCheckoutCart($token, $cartId, $request, $discountCodes, $context);
        [$salesChannelContext, $cart, $paymentMessages] = $this->selectPayment($request->payment, $salesChannelContext, $cart, $context);

        $status = $this->statusFor($cart->getLineItems()->count(), null !== $request->buyer);
        $addresses = $this->guestAddressPayloadResolver->resolveAddresses($request->fulfillment, null, $request->payment);
        $this->sessionManager->save(
            $salesChannelContext,
            $status->value,
            $request->buyer,
            $discountCodes,
            guestAddress: $addresses['billing'],
            guestShippingAddress: $addresses['shipping'],
        );

        return $this->openCheckout($cart, $salesChannelContext, $status, $request->buyer, $salesChannelContext->getToken(), $paymentMessages);
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
        [$completedOrderId, $sessionMetadata] = $this->loadSession($id, $resolution->salesChannelId);
        if (null !== $completedOrderId) {
            return $this->completedCheckout($completedOrderId, $id, $sessionMetadata, $context);
        }

        $contextToken = $this->sessionStore->contextToken($sessionMetadata, $id);
        [$salesChannelContext, $cart] = $this->cartGateway->loadCheckoutCart($contextToken, $context);
        $buyer = $this->sessionStore->buyer($sessionMetadata);

        return $this->openCheckout(
            $cart,
            $salesChannelContext,
            $this->statusFor($cart->getLineItems()->count(), null !== $buyer),
            $buyer,
            $id,
        );
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);
        [$placedOrderId, $sessionMetadata] = $this->loadSession($request->id, $resolution->salesChannelId);

        if (null !== $placedOrderId) {
            return $this->updatePlacedOrder($placedOrderId, $request, $sessionMetadata, $context);
        }

        if (($sessionMetadata['status'] ?? null) === 'completed') {
            throw new ValidationException('Completed checkout sessions cannot be updated.');
        }

        $contextToken = $this->sessionStore->contextToken($sessionMetadata, $request->id);
        $discountCodes = $this->discountCodes($request->discounts);
        [$salesChannelContext, $cart] = $this->cartGateway->synchronizeCheckoutCart(
            $contextToken,
            $request->lineItems,
            $discountCodes,
            $context,
        );
        [$salesChannelContext, $cart, $paymentMessages] = $this->selectPayment($request->payment, $salesChannelContext, $cart, $context);

        $buyer = $request->buyer ?? $this->sessionStore->buyer($sessionMetadata);
        $status = $this->statusFor($cart->getLineItems()->count(), null !== $buyer);

        $addresses = $this->guestAddressPayloadResolver->resolveAddresses($request->fulfillment, $sessionMetadata, $request->payment);

        $this->sessionManager->saveForCheckoutId(
            $request->id,
            $salesChannelContext,
            $status->value,
            $buyer,
            $discountCodes,
            guestAddress: $addresses['billing'],
            guestShippingAddress: $addresses['shipping'],
        );

        return $this->openCheckout($cart, $salesChannelContext, $status, $buyer, $request->id, $paymentMessages);
    }

    /**
     * The payment-aware entry point.
     *
     * `completeCheckout()` below carries no instrument, so the payment gate refuses it.
     */
    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout
    {
        return $this->completeCheckoutInternal($request->id, $context, $request->selectedInstrument());
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->completeCheckoutInternal($id, $context, null);
    }

    private function completeCheckoutInternal(string $id, RequestContext $context, ?PaymentInstrument $instrument): Checkout
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);
        [$completedOrderId, $sessionMetadata] = $this->loadSession($id, $resolution->salesChannelId);
        if (null !== $completedOrderId) {
            return $this->completePlacedOrder($completedOrderId, $id, $sessionMetadata, $context, $instrument);
        }

        $contextToken = $this->sessionStore->contextToken($sessionMetadata, $id);
        [$salesChannelContext, $cart] = $this->cartGateway->loadCheckoutCart($contextToken, $context);

        $buyer = $this->sessionStore->buyer($sessionMetadata);
        $status = $this->statusFor($cart->getLineItems()->count(), null !== $buyer);

        $refusal = $this->paymentSelector->refusal($instrument, $context);
        if (null !== $refusal) {
            return $this->openCheckout($cart, $salesChannelContext, $status, $buyer, $id, [$refusal]);
        }

        return $this->checkoutCompleter->complete($id, $sessionMetadata, $cart, $salesChannelContext, $context, $status, $instrument)
            ?? $this->getCheckout($id, $context);
    }

    /**
     * Replays a checkout whose order is placed, or pays the order again when its payment failed.
     *
     * @param array<string, mixed> $sessionMetadata
     */
    private function completePlacedOrder(string $orderId, string $checkoutId, array $sessionMetadata, RequestContext $context, ?PaymentInstrument $instrument): Checkout
    {
        $salesChannelContext = $this->completedCheckoutContext($sessionMetadata, $context);
        $order = $this->orderGateway->getOrderForSalesChannelContext($orderId, $salesChannelContext, $sessionMetadata);
        $payment = CompletionPaymentResult::ofPlacedOrder($sessionMetadata, $order);

        if (!$payment->canBePaidAgain()) {
            return $this->placedCheckout($order, $checkoutId, $salesChannelContext, $context, $payment);
        }

        $refusal = $this->paymentSelector->refusal($instrument, $context);
        if (null !== $refusal) {
            return $this->placedCheckout($order, $checkoutId, $salesChannelContext, $context, $payment, [$refusal]);
        }

        return $this->checkoutCompleter->retryPayment($checkoutId, $orderId, $salesChannelContext, $context, $instrument);
    }

    /**
     * The order cannot change, so only its payment can: buyer, fulfillment and discounts of the request are ignored.
     *
     * @param array<string, mixed> $sessionMetadata
     */
    private function updatePlacedOrder(string $orderId, CheckoutUpdateRequest $request, array $sessionMetadata, RequestContext $context): Checkout
    {
        $salesChannelContext = $this->completedCheckoutContext($sessionMetadata, $context);
        $order = $this->orderGateway->getOrderForSalesChannelContext($orderId, $salesChannelContext, $sessionMetadata);
        $payment = CompletionPaymentResult::ofPlacedOrder($sessionMetadata, $order);

        if (!$payment->canBePaidAgain()) {
            throw new ValidationException('Completed checkout sessions cannot be updated.');
        }

        $refusal = $this->placedOrderLineItemsRefusal($order, $request->lineItems)
            ?? $this->paymentSelector->refusalForPlacedOrder($request->payment, $salesChannelContext, $context);
        if (null !== $refusal) {
            return $this->placedCheckout($order, $request->id, $salesChannelContext, $context, $payment, [$refusal]);
        }

        return $this->checkoutCompleter->markReadyToPayAgain($request->id, $orderId, $salesChannelContext, $context);
    }

    /**
     * @param list<UcpLineItem> $requestedLineItems an empty list leaves the line items of the order as they are
     */
    private function placedOrderLineItemsRefusal(OrderEntity $order, array $requestedLineItems): ?Message
    {
        if ([] === $requestedLineItems) {
            return null;
        }

        $quantityByOrderedProductId = [];
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if (LineItem::PRODUCT_LINE_ITEM_TYPE === $lineItem->getType()) {
                $productId = $lineItem->getReferencedId() ?? $lineItem->getIdentifier();
                $quantityByOrderedProductId[$productId] = ($quantityByOrderedProductId[$productId] ?? 0) + $lineItem->getQuantity();
            }
        }

        $quantityByRequestedProductId = [];
        foreach ($requestedLineItems as $lineItem) {
            $quantityByRequestedProductId[$lineItem->id] = ($quantityByRequestedProductId[$lineItem->id] ?? 0) + $lineItem->quantity;
        }

        ksort($quantityByOrderedProductId);
        ksort($quantityByRequestedProductId);
        if ($quantityByOrderedProductId === $quantityByRequestedProductId) {
            return null;
        }

        return new Message(
            'error',
            'The order is placed, so only its payment can change. Send the line items of the order, or none.',
            'recoverable',
            'order_placed',
            '$.line_items',
        );
    }

    /**
     * Makes the cart and totals of an open checkout follow the payment method of the selected instrument.
     *
     * @return array{0: SalesChannelContext, 1: Cart, 2: list<Message>} a refused instrument leaves context and cart as they are and adds its refusal
     */
    private function selectPayment(?PaymentInstrument $instrument, SalesChannelContext $salesChannelContext, Cart $cart, RequestContext $context): array
    {
        if (null === $instrument) {
            return [$salesChannelContext, $cart, []];
        }

        $selected = $this->paymentSelector->select($instrument, $salesChannelContext, $context);
        if ($selected instanceof Message) {
            return [$salesChannelContext, $cart, [$selected]];
        }

        return [$selected, $this->cartGateway->loadCart($selected), []];
    }

    /**
     * @return array{0: string|null, 1: array<string, mixed>} the completed order id, if any, and the session metadata
     */
    private function loadSession(string $id, string $salesChannelId): array
    {
        // Read before the metadata: CheckoutCompleter::complete() saves the metadata with the rotated context token first.
        $completedOrderId = $this->completionStore->completedOrderId($id);
        $sessionMetadata = $this->sessionStore->load($id, $salesChannelId);

        return [$completedOrderId ?? $this->legacyCompletedOrderId($sessionMetadata), $sessionMetadata];
    }

    /**
     * @param array<string, mixed> $sessionMetadata
     */
    private function legacyCompletedOrderId(array $sessionMetadata): ?string
    {
        if (($sessionMetadata['status'] ?? null) !== 'completed') {
            return null;
        }

        $legacyCompletedOrderId = $sessionMetadata['orderId'] ?? null;

        return \is_string($legacyCompletedOrderId) && '' !== $legacyCompletedOrderId ? $legacyCompletedOrderId : null;
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);
        $sessionMetadata = $this->sessionStore->load($id, $resolution->salesChannelId);
        $buyer = $this->sessionStore->buyer($sessionMetadata);

        $contextToken = $this->sessionStore->contextToken($sessionMetadata, $id);
        [$salesChannelContext] = $this->cartGateway->loadCheckoutCart($contextToken, $context);
        $cart = $this->cartGateway->cancelCart($contextToken, $context);

        $this->sessionManager->saveForCheckoutId($id, $salesChannelContext, CheckoutStatus::Canceled->value, $buyer);

        return $this->openCheckout(new Cart($cart->id), $salesChannelContext, CheckoutStatus::Canceled, $buyer, $id);
    }

    /**
     * @param list<Message> $messages
     */
    private function openCheckout(
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        CheckoutStatus $status,
        ?Buyer $buyer,
        string $checkoutId,
        array $messages = [],
    ): Checkout {
        // The cart is stored under the context token, which differs from the checkout id once a refused completion rotated it.
        $cart->setToken($checkoutId);

        return $this->mapper->toCheckout(
            $cart,
            $salesChannelContext,
            $status,
            $buyer,
            $this->continueUrlBuilder->build($checkoutId, $salesChannelContext->getSalesChannelId()),
            legalLinks: $this->legalLinkBuilder->build($salesChannelContext),
            messages: $messages,
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
     * @param array<string, mixed> $sessionMetadata
     */
    private function completedCheckoutContext(array $sessionMetadata, RequestContext $context): SalesChannelContext
    {
        $contextToken = $this->sessionStore->storedContextToken($sessionMetadata);
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

    /**
     * @param array<string, mixed> $sessionMetadata
     */
    private function completedCheckout(string $orderId, string $checkoutId, array $sessionMetadata, RequestContext $context): Checkout
    {
        $salesChannelContext = $this->completedCheckoutContext($sessionMetadata, $context);
        $order = $this->orderGateway->getOrderForSalesChannelContext($orderId, $salesChannelContext, $sessionMetadata);

        return $this->placedCheckout($order, $checkoutId, $salesChannelContext, $context, CompletionPaymentResult::ofPlacedOrder($sessionMetadata, $order));
    }

    /**
     * @param list<Message>|null $messages
     */
    private function placedCheckout(OrderEntity $order, string $checkoutId, SalesChannelContext $salesChannelContext, RequestContext $context, CompletionPaymentResult $payment, ?array $messages = null): Checkout
    {
        return $this->mapper->toCompletedCheckout(
            $order,
            $checkoutId,
            $order->getCurrency()?->getIsoCode() ?? 'EUR',
            $this->continueUrlBuilder->build($checkoutId, $salesChannelContext->getSalesChannelId()),
            orderPermalinkUrl: $this->orderPermalinkBuilder->build($order, $context),
            legalLinks: $this->legalLinkBuilder->build($salesChannelContext),
            payment: $payment,
            messages: $messages,
        );
    }
}
