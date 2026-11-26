<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[Package('after-sales')]
interface OrderGatewayInterface
{
    /**
     * @param PaymentInstrument|null $instrument handed to the payment handler of the order's payment method
     */
    public function placeOrder(Cart $cart, SalesChannelContext $context, ?PaymentInstrument $instrument = null): OrderEntity;

    public function getOrder(string $orderId, RequestContext $requestContext): OrderEntity;

    /**
     * @param array<string, mixed>|null $checkoutMetadata
     */
    public function getOrderForSalesChannelContext(string $orderId, SalesChannelContext $context, ?array $checkoutMetadata = null): OrderEntity;
}
