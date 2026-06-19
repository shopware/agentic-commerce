<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\RequestContext;

interface OrderGatewayInterface
{
    public function placeOrder(Cart $cart, SalesChannelContext $context): OrderEntity;

    public function getOrder(string $orderId, RequestContext $requestContext): OrderEntity;

    /**
     * @param array<string, mixed>|null $checkoutMetadata
     */
    public function getOrderForSalesChannelContext(string $orderId, SalesChannelContext $context, ?array $checkoutMetadata = null): OrderEntity;
}
