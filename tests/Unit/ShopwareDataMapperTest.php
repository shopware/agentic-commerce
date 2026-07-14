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

        $view = (new ShopwareDataMapper())->toOrderView($order);

        self::assertCount(1, $view->messages);
        self::assertSame($expectedMessage, $view->messages[0]->toArray());
    }

    #[Test]
    public function testOrderWithoutStateDoesNotIncludeStateMessage(): void
    {
        $order = $this->order();

        self::assertSame([], (new ShopwareDataMapper())->toOrderView($order)->messages);
    }

    #[Test]
    public function testCancelledOrderIncludesCancellationAdjustment(): void
    {
        $order = $this->order();
        $order->setUpdatedAt(new \DateTimeImmutable('2026-07-14T10:30:00+00:00'));
        $state = new StateMachineStateEntity();
        $state->setTechnicalName(OrderStates::STATE_CANCELLED);
        $order->setStateMachineState($state);

        $view = (new ShopwareDataMapper())->toOrderView($order);

        self::assertSame([], $view->messages);
        self::assertSame([[
            'id' => '99999999999999999999999999999999-cancellation',
            'type' => 'cancellation',
            'occurred_at' => '2026-07-14T10:30:00+00:00',
            'status' => 'completed',
            'description' => 'The merchant cancelled this order.',
        ]], $view->extra['adjustments']);
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
