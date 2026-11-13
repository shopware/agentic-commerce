<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Shopware\Core\Checkout\Cart\Cart;
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
    ) {
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        $cartId = $this->checkoutCartId($request);
        $token = $cartId ?? $this->contextTokenGenerator->generate();
        $discountCodes = $this->discountCodes($request->discounts);
        [$salesChannelContext, $cart] = $this->createOrReuseCheckoutCart($token, $cartId, $request, $discountCodes, $context);

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

        return $this->mapper->toCheckout(
            $cart,
            $salesChannelContext,
            $status,
            $request->buyer,
            $this->continueUrlBuilder->build($salesChannelContext->getToken(), $salesChannelContext->getSalesChannelId()),
            legalLinks: $this->legalLinkBuilder->build($salesChannelContext),
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
        [$completedOrderId, $sessionMetadata] = $this->loadSession($id, $resolution->salesChannelId);
        if (null !== $completedOrderId) {
            return $this->completedCheckout($completedOrderId, $id, $sessionMetadata, $context);
        }

        $contextToken = $this->sessionStore->contextToken($sessionMetadata, $id);
        [$salesChannelContext, $cart] = $this->cartGateway->loadCheckoutCart($contextToken, $context);
        $buyer = $this->sessionStore->buyer($sessionMetadata);

        return $this->mapper->toCheckout(
            $cart,
            $salesChannelContext,
            $this->statusFor($cart->getLineItems()->count(), null !== $buyer),
            $buyer,
            $this->continueUrlBuilder->build($id, $salesChannelContext->getSalesChannelId()),
            legalLinks: $this->legalLinkBuilder->build($salesChannelContext),
        );
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);
        $sessionMetadata = $this->sessionStore->load($request->id, $resolution->salesChannelId);

        if (($sessionMetadata['status'] ?? null) === 'completed' || null !== $this->completionStore->completedOrderId($request->id)) {
            throw new ValidationException('Completed checkout sessions cannot be updated.');
        }

        $discountCodes = $this->discountCodes($request->discounts);
        [$salesChannelContext, $cart] = $this->cartGateway->synchronizeCheckoutCart(
            $request->id,
            $request->lineItems,
            $discountCodes,
            $context,
        );

        $buyer = $request->buyer ?? $this->sessionStore->buyer($sessionMetadata);
        $status = $this->statusFor($cart->getLineItems()->count(), null !== $buyer);

        $addresses = $this->guestAddressPayloadResolver->resolveAddresses($request->fulfillment, $sessionMetadata, $request->payment);

        $this->sessionManager->save(
            $salesChannelContext,
            $status->value,
            $buyer,
            $discountCodes,
            guestAddress: $addresses['billing'],
            guestShippingAddress: $addresses['shipping'],
        );

        return $this->mapper->toCheckout(
            $cart,
            $salesChannelContext,
            $status,
            $buyer,
            $this->continueUrlBuilder->build($request->id, $salesChannelContext->getSalesChannelId()),
            legalLinks: $this->legalLinkBuilder->build($salesChannelContext),
        );
    }

    /**
     * The payment-aware entry point.
     *
     * `completeCheckout()` below is kept and still works: the SDK calls this one when the
     * adapter opts in, and that one otherwise, so nothing that already calls it breaks.
     */
    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout
    {
        return $this->completeCheckoutInternal($request->id, $context, self::selectedInstrument($request));
    }

    /**
     * The first instrument on a completion, which is as close to "the one the agent chose" as
     * this release can get.
     *
     * `payment.json` models payment as `{"instruments": [...]}` and marks the buyer's choice with
     * `selected` at the instrument top level. The SDK cannot report it: `PaymentInstrument` has no
     * such property and `HttpPayloadMapper::toPaymentInstrument()` drops the field, so nothing
     * reaches this method that could distinguish one instrument from another. An earlier version
     * looked for `credential['selected']`, one level too deep, which could never be true and made
     * the first-instrument fallback the only behaviour while reading as though selection worked.
     *
     * The asymmetry is upstream: the SDK honours `selected` on create and update, where it reads
     * the raw payload before mapping, and not on complete, where it maps the whole list. Tracked
     * in agentic-commerce-alliance/ucp-php-sdk#190; once that ships, select on the property here
     * and drop this note.
     */
    private static function selectedInstrument(CheckoutCompleteRequest $request): ?PaymentInstrument
    {
        return $request->instruments[0] ?? null;
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
            return $this->completedCheckout($completedOrderId, $id, $sessionMetadata, $context);
        }

        $contextToken = $this->sessionStore->contextToken($sessionMetadata, $id);
        [$salesChannelContext, $cart] = $this->cartGateway->loadCheckoutCart($contextToken, $context);

        return $this->checkoutCompleter->complete($id, $sessionMetadata, $cart, $salesChannelContext, $context, $instrument)
            ?? $this->getCheckout($id, $context);
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

        [$salesChannelContext] = $this->cartGateway->loadCheckoutCart($id, $context);
        $cart = $this->cartGateway->cancelCart($id, $context);

        $this->sessionManager->save($salesChannelContext, CheckoutStatus::Canceled->value, $buyer);

        return $this->mapper->toCheckout(
            new Cart($cart->id),
            $salesChannelContext,
            CheckoutStatus::Canceled,
            $buyer,
            $this->continueUrlBuilder->build($id, $salesChannelContext->getSalesChannelId()),
            legalLinks: $this->legalLinkBuilder->build($salesChannelContext),
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

        return $this->mapper->toCompletedCheckout(
            $order,
            $checkoutId,
            $order->getCurrency()?->getIsoCode() ?? 'EUR',
            $this->continueUrlBuilder->build($checkoutId, $salesChannelContext->getSalesChannelId()),
            orderPermalinkUrl: $this->orderPermalinkBuilder->build($order, $context),
            legalLinks: $this->legalLinkBuilder->build($salesChannelContext),
        );
    }
}
