<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Model\RequestContext;

final readonly class ShopwareOrderGateway
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private SalesChannelContextResolver $contextResolver,
        private ContextTokenGenerator $contextTokenGenerator,
        private AbstractCartOrderRoute $cartOrderRoute,
        private EntityRepository $orderRepository,
    ) {
    }

    public function placeOrder(\Shopware\Core\Checkout\Cart\Cart $cart, \Shopware\Core\System\SalesChannel\SalesChannelContext $context): OrderEntity
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
