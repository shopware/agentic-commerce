<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPaymentState;

/**
 * @internal
 */
#[CoversClass(OrderPaymentState::class)]
final class OrderPaymentStateTest extends TestCase
{
    #[Test]
    public function testPaidTransactionIsPaid(): void
    {
        self::assertTrue(OrderPaymentState::isPaid($this->orderWithState(OrderTransactionStates::STATE_PAID)));
    }

    #[Test]
    public function testOpenTransactionIsNotPaid(): void
    {
        self::assertFalse(OrderPaymentState::isPaid($this->orderWithState(OrderTransactionStates::STATE_OPEN)));
    }

    #[Test]
    public function testNoTransactionsIsNotPaid(): void
    {
        $order = new OrderEntity();
        $order->setId('0000000000000000000000000000000c');
        $order->setTransactions(new OrderTransactionCollection());

        self::assertFalse(OrderPaymentState::isPaid($order));
    }

    #[Test]
    public function testUsesMostRecentTransaction(): void
    {
        $failed = $this->transaction('0000000000000000000000000000000a', OrderTransactionStates::STATE_FAILED);
        $paid = $this->transaction('0000000000000000000000000000000b', OrderTransactionStates::STATE_PAID);

        $order = new OrderEntity();
        $order->setId('0000000000000000000000000000000c');
        $order->setTransactions(new OrderTransactionCollection([$failed, $paid]));

        self::assertTrue(OrderPaymentState::isPaid($order));
    }

    private function orderWithState(string $technicalName): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('0000000000000000000000000000000c');
        $order->setTransactions(new OrderTransactionCollection([
            $this->transaction('0000000000000000000000000000000b', $technicalName),
        ]));

        return $order;
    }

    private function transaction(string $id, string $technicalName): OrderTransactionEntity
    {
        $state = new StateMachineStateEntity();
        $state->setId('0000000000000000000000000000000a');
        $state->setTechnicalName($technicalName);

        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setStateMachineState($state);

        return $transaction;
    }
}
