<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareOrderGateway implements OrderGatewayInterface
{
    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly AbstractCartOrderRoute $cartOrderRoute,
        private readonly AbstractOrderRoute $orderRoute,
        private readonly CheckoutSessionStore $sessionStore,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function placeOrder(Cart $cart, SalesChannelContext $context): OrderEntity
    {
        return $this->cartOrderRoute->order($cart, $context, new RequestDataBag())->getOrder();
    }

    public function getOrder(string $orderId, RequestContext $requestContext): OrderEntity
    {
        $contextToken = $this->requireContextToken($requestContext);
        $context = $this->contextResolver->resolve($contextToken, $requestContext);
        if (null !== $context->getCustomer()) {
            return $this->getOrderForSalesChannelContext($orderId, $context);
        }

        $metadata = $this->sessionStore->load($contextToken, $context->getSalesChannelId());

        return $this->getOrderForSalesChannelContext($orderId, $context, $metadata);
    }

    /**
     * @param array<string, mixed>|null $checkoutMetadata
     */
    public function getOrderForSalesChannelContext(string $orderId, SalesChannelContext $context, ?array $checkoutMetadata = null): OrderEntity
    {
        $request = new Request();
        $criteria = $this->orderCriteria($orderId);

        if (null === $context->getCustomer() && null !== $checkoutMetadata) {
            $guestOrderRequest = $this->guestOrderRequest($orderId, $checkoutMetadata);
            if (null !== $guestOrderRequest) {
                [$request, $deepLinkCode] = $guestOrderRequest;
                $criteria->addFilter(new EqualsFilter('order.deepLinkCode', $deepLinkCode));
            }
        }

        return $this->loadOrder($request, $context, $criteria, $orderId);
    }

    private function loadOrder(Request $request, SalesChannelContext $context, Criteria $criteria, string $orderId): OrderEntity
    {
        $order = $this->orderRoute->load($request, $context, $criteria)->getOrders()->first();

        if (!$order instanceof OrderEntity) {
            throw new ResourceNotFoundException(\sprintf('Order "%s" was not found for this sales channel.', $orderId));
        }

        return $order;
    }

    private function orderCriteria(string $orderId): Criteria
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('lineItems.cover');

        return $criteria;
    }

    /**
     * @param array<string, mixed> $checkoutMetadata
     *
     * @return array{0: Request, 1: string}|null
     */
    private function guestOrderRequest(string $orderId, array $checkoutMetadata): ?array
    {
        if (($checkoutMetadata['orderId'] ?? null) !== $orderId) {
            return null;
        }

        $deepLinkCode = $checkoutMetadata['orderDeepLinkCode'] ?? null;
        $buyer = $this->sessionStore->buyer($checkoutMetadata);
        $email = $buyer?->email;
        $guestAddress = $this->sessionStore->guestAddress($checkoutMetadata);

        if (
            !\is_string($deepLinkCode)
            || '' === $deepLinkCode
            || !\is_string($email)
            || '' === $email
            || null === $guestAddress
        ) {
            return null;
        }

        return [
            new Request([
                'email' => $email,
                'zipcode' => $guestAddress['zipcode'],
            ]),
            $deepLinkCode,
        ];
    }

    private function requireContextToken(RequestContext $requestContext): string
    {
        $incomingToken = $this->incomingContextToken();
        if (null !== $incomingToken) {
            return $incomingToken;
        }

        $headers = array_change_key_case($requestContext->headers, \CASE_LOWER);
        $token = $headers[strtolower(PlatformRequest::HEADER_CONTEXT_TOKEN)] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new ValidationException('Order reads require a Shopware customer context token.', ['$.headers.'.PlatformRequest::HEADER_CONTEXT_TOKEN.' is required']);
        }

        return $token;
    }

    private function incomingContextToken(): ?string
    {
        $token = $this->requestStack->getCurrentRequest()?->server->get('HTTP_SW_CONTEXT_TOKEN');

        return \is_string($token) && '' !== $token ? $token : null;
    }
}
