<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Ucp\Sdk\Adapter\DiscountAdapterInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[Package('checkout')]
final class ShopwareDiscountAdapter implements DiscountAdapterInterface
{
    public function __construct(
        private readonly ShopwareCartGateway $gateway,
    ) {
    }

    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart
    {
        return $this->gateway->applyDiscountCode($cartId, $discount->code, $context);
    }
}
