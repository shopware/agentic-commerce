<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;

/** @internal */
final class ShopwareDataMapperTest extends TestCase
{
    #[Test]
    public function testOrderViewUsesSpecDerivedOrderResponseShape(): void
    {
        $payload = (new ShopwareDataMapper())
            ->toOrderView($this->order('order-1'), 'https://shop.example/account/order/order-1', 'checkout-1')
            ->toArray();

        self::assertSame('checkout-1', $payload['checkout_id']);
        self::assertSame('https://shop.example/account/order/order-1', $payload['permalink_url']);
        self::assertSame([], $payload['fulfillment']);
        self::assertSame(['subtotal', 'fulfillment', 'total', 'tax'], array_column($payload['totals'], 'type'));
    }

    private function order(string $orderId): OrderEntity
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setPrice(new CartPrice(20.0, 20.0, 20.0, $taxes, $taxRules, CartPrice::TAX_STATE_GROSS));
        $order->setShippingCosts(new CalculatedPrice(4.99, 4.99, $taxes, $taxRules));

        return $order;
    }
}
