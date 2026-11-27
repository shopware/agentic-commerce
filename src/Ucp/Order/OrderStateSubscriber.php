<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Order;

use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** @internal */
#[Package('after-sales')]
final class OrderStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CheckoutCompletionStoreInterface $completionStore,
        private readonly OrderWebhookEmitter $webhookEmitter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.'.OrderStates::STATE_MACHINE.'.'.OrderStates::STATE_OPEN => 'onOrderStateChanged',
            'state_enter.'.OrderStates::STATE_MACHINE.'.'.OrderStates::STATE_IN_PROGRESS => 'onOrderStateChanged',
            'state_enter.'.OrderStates::STATE_MACHINE.'.'.OrderStates::STATE_COMPLETED => 'onOrderStateChanged',
            'state_enter.'.OrderStates::STATE_MACHINE.'.'.OrderStates::STATE_CANCELLED => 'onOrderStateChanged',
        ];
    }

    public function onOrderStateChanged(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $event->getOrder();
        $checkoutId = $this->completionStore->completedCheckoutId($order->getId());
        if (null === $checkoutId) {
            return;
        }

        $this->webhookEmitter->publish('order.updated', $order, $checkoutId);
    }
}
