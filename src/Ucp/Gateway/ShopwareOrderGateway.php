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

        if (null === $context->getCustomer()) {
            $guestOrderRequest = null !== $checkoutMetadata
                ? $this->guestOrderRequest($orderId, $checkoutMetadata)
                : null;

            if (null === $guestOrderRequest) {
                throw $this->guestOrderNotReadable($orderId);
            }

            [$request, $deepLinkCode] = $guestOrderRequest;
            $criteria->addFilter(new EqualsFilter('order.deepLinkCode', $deepLinkCode));
        }

        return $this->loadOrder($request, $context, $criteria, $orderId);
    }

    /**
     * Refuses a guest order read in UCP's vocabulary instead of Shopware's.
     *
     * Without a customer and without the guest credentials, `OrderRoute` refuses with
     * Shopware's own `Customer is not logged in.` — a 403 that reaches the agent as
     * `code: invalid_request`, `severity: recoverable`. Every part of that misleads:
     * nothing about the request is invalid, and a UCP agent cannot log in, so it is
     * not recoverable by anything the agent can do. It would retry on our advice.
     *
     * **The refusal itself is correct and stays.** The order specification requires
     * one — "the business MUST authenticate requests to order data before returning a
     * response" — and only *permits* the case we are in:
     *
     *     | Platform credentials | Orders originated by the platform |
     *
     * with businesses that "MAY allow access for orders the platform originated". We
     * cannot honour that yet, and the reason is worth stating rather than implying:
     * the only credential on the request is the sales-channel access key, which is
     * shared and semi-public. It identifies the channel, not the platform that placed
     * this order, so serving an order on the strength of it would let any holder of
     * that key read any order by id. Attributing orders to the originating agent
     * profile — which the `UCP-Agent` header does carry — is what would make that MAY
     * safe to take, and it is a design change rather than a fix.
     *
     * `not_found` rather than a forbidden-shaped code, on purpose: with a shared key,
     * the difference between "exists but not yours" and "does not exist" is an
     * enumeration oracle. This is the same answer `loadOrder()` gives for an order that
     * genuinely is not there, so the two are indistinguishable from outside.
     *
     * The message names the two channels the specification actually intends here:
     * `permalink_url`, "the authoritative reference for the full order experience",
     * and the order webhook, which platforms "SHOULD rely on as the primary order
     * update channel", using Get Order "for reconciliation".
     */
    private function guestOrderNotReadable(string $orderId): ResourceNotFoundException
    {
        return new ResourceNotFoundException(\sprintf(
            'Order "%s" is not available to this request. A guest order can only be read back by the checkout session that placed it, and a platform credential does not authenticate one. Use the permalink_url returned by checkout.complete, or the order webhook, which is the channel UCP intends for order state.',
            $orderId,
        ));
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
        $criteria->addAssociation('stateMachineState');

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
