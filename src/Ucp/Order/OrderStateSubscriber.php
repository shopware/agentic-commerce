<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Order;

use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderStates;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/** @internal */
final class OrderStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CheckoutCompletionStoreInterface $completionStore,
        private readonly OrderPermalinkBuilder $orderPermalinkBuilder,
        private readonly UcpConfigService $configService,
        private readonly ShopwareDataMapperInterface $mapper,
        private readonly OrderWebhookPublisherInterface $webhookPublisher,
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

        $salesChannelId = $order->getSalesChannelId();
        $config = $this->configService->getConfig($salesChannelId);
        if (null === $config->webhookUrlOverride) {
            return;
        }

        $runtimeConfiguration = $config->toRuntimeConfiguration('', $salesChannelId);
        $host = parse_url($runtimeConfiguration->baseUri, \PHP_URL_HOST) ?: '';
        // The same order URL the completion and the read return: `permalink_url` means one
        // thing, and a continue URL is a checkout return address rather than an order page.
        $requestContext = new RequestContext($host, runtimeConfiguration: $runtimeConfiguration);
        $permalinkUrl = $this->orderPermalinkBuilder->build($order, $requestContext);

        $this->webhookPublisher->publish(
            $config->webhookUrlOverride,
            new OrderWebhookPayload('order.updated', $order->getId(), [
                'order' => $this->mapper->toOrderView($order, $permalinkUrl, $checkoutId)->toArray(),
            ]),
            $requestContext,
        );
    }
}
