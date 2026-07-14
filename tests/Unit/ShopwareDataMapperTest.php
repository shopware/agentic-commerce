<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;

/** @internal */
#[CoversClass(ShopwareDataMapper::class)]
final class ShopwareDataMapperTest extends TestCase
{
    #[Test]
    public function testCancelledOrderIncludesCancellationMessage(): void
    {
        $order = $this->order();
        $state = new StateMachineStateEntity();
        $state->setTechnicalName(OrderStates::STATE_CANCELLED);
        $order->setStateMachineState($state);

        $view = (new ShopwareDataMapper())->toOrderView($order)->toArray();

        self::assertSame([[
            'type' => 'error',
            'content' => 'The merchant cancelled this order.',
            'severity' => 'unrecoverable',
            'code' => 'order_cancelled',
        ]], $view['messages']);
    }

    #[Test]
    public function testActiveOrderDoesNotIncludeCancellationMessage(): void
    {
        $order = $this->order();
        $state = new StateMachineStateEntity();
        $state->setTechnicalName(OrderStates::STATE_OPEN);
        $order->setStateMachineState($state);

        self::assertSame([], (new ShopwareDataMapper())->toOrderView($order)->messages);
    }

    private function order(): OrderEntity
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();
        $order = new OrderEntity();
        $order->setId('99999999999999999999999999999999');
        $order->setPrice(new CartPrice(10.0, 10.0, 10.0, $taxes, $taxRules, CartPrice::TAX_STATE_GROSS));
        $order->setShippingCosts(new CalculatedPrice(0.0, 0.0, $taxes, $taxRules));

        return $order;
    }
}
