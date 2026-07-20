<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Reads whether an order has actually been paid, from its most recent
 * transaction's state machine state. Requires the order to be loaded with the
 * `transactions.stateMachineState` association.
 *
 * @internal
 */
final class OrderPaymentState
{
    public static function isPaid(OrderEntity $order): bool
    {
        $transaction = $order->getTransactions()?->last();

        return OrderTransactionStates::STATE_PAID === $transaction?->getStateMachineState()?->getTechnicalName();
    }
}
