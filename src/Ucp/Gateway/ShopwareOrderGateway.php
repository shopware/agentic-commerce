<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Model\RequestContext;

final class ShopwareOrderGateway implements OrderGatewayInterface
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly ContextTokenGenerator $contextTokenGenerator,
        private readonly AbstractCartOrderRoute $cartOrderRoute,
        private readonly EntityRepository $orderRepository,
    ) {
    }

    public function placeOrder(Cart $cart, SalesChannelContext $context): OrderEntity
    {
        return $this->cartOrderRoute->order($cart, $context, new RequestDataBag())->getOrder();
    }

    public function getOrder(string $orderId, RequestContext $requestContext): OrderEntity
    {
        $context = $this->contextResolver->resolve($this->contextTokenGenerator->generate(), $requestContext);
        $criteria = new Criteria([$orderId]);
        $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannelId()));
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('lineItems.cover');

        $order = $this->orderRepository->search($criteria, $context->getContext())->first();
        if (!$order instanceof OrderEntity) {
            throw new ResourceNotFoundException(\sprintf('Order "%s" was not found for this sales channel.', $orderId));
        }

        return $order;
    }
}
