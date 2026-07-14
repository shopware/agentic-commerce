<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /**
     * @param array<string, string> $expectedMessage
     */
    #[Test]
    #[DataProvider('orderStateProvider')]
    public function testOrderIncludesStateMessage(string $stateName, array $expectedMessage): void
    {
        $order = $this->order();
        $state = new StateMachineStateEntity();
        $state->setTechnicalName($stateName);
        $order->setStateMachineState($state);

        $view = (new ShopwareDataMapper())->toOrderView($order)->toArray();

        self::assertSame([$expectedMessage], $view['messages']);
    }

    #[Test]
    public function testOrderWithoutStateDoesNotIncludeStateMessage(): void
    {
        $order = $this->order();

        self::assertSame([], (new ShopwareDataMapper())->toOrderView($order)->messages);
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function orderStateProvider(): iterable
    {
        yield 'open' => [OrderStates::STATE_OPEN, [
            'type' => 'info',
            'content' => 'The order is open.',
            'code' => 'order_open',
        ]];

        yield 'in progress' => [OrderStates::STATE_IN_PROGRESS, [
            'type' => 'info',
            'content' => 'The merchant is processing this order.',
            'code' => 'order_in_progress',
        ]];

        yield 'completed' => [OrderStates::STATE_COMPLETED, [
            'type' => 'info',
            'content' => 'The merchant completed this order.',
            'code' => 'order_completed',
        ]];

        yield 'cancelled' => [OrderStates::STATE_CANCELLED, [
            'type' => 'error',
            'content' => 'The merchant cancelled this order.',
            'severity' => 'unrecoverable',
            'code' => 'order_cancelled',
        ]];
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
