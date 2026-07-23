<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilderInterface;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Swag\AgenticCommerce\Ucp\Order\OrderStateSubscriber;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Model\Webhook\WebhookDispatchResult;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/** @internal */
#[CoversClass(OrderStateSubscriber::class)]
final class OrderStateSubscriberTest extends TestCase
{
    private const ORDER_ID = '99999999999999999999999999999999';
    private const SALES_CHANNEL_ID = '22222222222222222222222222222222';
    private const CHECKOUT_ID = 'checkout-id';

    #[Test]
    public function testSubscribesToAllOrderStates(): void
    {
        self::assertSame([
            'state_enter.order.state.open' => 'onOrderStateChanged',
            'state_enter.order.state.in_progress' => 'onOrderStateChanged',
            'state_enter.order.state.completed' => 'onOrderStateChanged',
            'state_enter.order.state.cancelled' => 'onOrderStateChanged',
        ], OrderStateSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function testOrderStateChangePublishesUpdatedUcpOrder(): void
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->expects(static::once())
            ->method('completedCheckoutId')
            ->with(self::ORDER_ID)
            ->willReturn(self::CHECKOUT_ID);

        $continueUrlBuilder = $this->createMock(CheckoutContinueUrlBuilderInterface::class);
        $continueUrlBuilder->expects(static::once())
            ->method('build')
            ->with(self::CHECKOUT_ID, self::SALES_CHANNEL_ID)
            ->willReturn('https://shop.example/account/order/'.self::ORDER_ID);

        $mapper = $this->createMock(ShopwareDataMapperInterface::class);
        $mapper->expects(static::once())
            ->method('toOrderView')
            ->with($order, 'https://shop.example/account/order/'.self::ORDER_ID, self::CHECKOUT_ID)
            ->willReturn(new OrderView(self::ORDER_ID, 'EUR', [], []));

        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher->expects(static::once())
            ->method('publish')
            ->with(
                'https://agent.example/webhook',
                static::callback(static fn (OrderWebhookPayload $payload): bool => 'order.updated' === $payload->event
                    && self::ORDER_ID === $payload->orderId
                    && self::ORDER_ID === $payload->payload['order']['id']),
                static::callback(static fn (RequestContext $context): bool => 'shop.example' === $context->host
                    && self::SALES_CHANNEL_ID === $context->runtimeConfiguration?->tenantIdentifier),
            )
            ->willReturn(new WebhookDispatchResult('https://agent.example/webhook', 200, true));

        $subscriber = new OrderStateSubscriber(
            $completionStore,
            $continueUrlBuilder,
            $this->configService(new UcpConfig(
                profileDomain: 'https://shop.example',
                webhookUrlOverride: 'https://agent.example/webhook',
            )),
            $mapper,
            $publisher,
        );

        $subscriber->onOrderStateChanged(new OrderStateMachineStateChangeEvent(
            'state_enter.'.OrderStates::STATE_MACHINE.'.'.OrderStates::STATE_IN_PROGRESS,
            $order,
            Context::createDefaultContext(),
        ));
    }

    #[Test]
    public function testStateChangeOfNonUcpOrderDoesNotPublishWebhook(): void
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedCheckoutId')->willReturn(null);
        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher->expects(static::never())->method('publish');

        $subscriber = new OrderStateSubscriber(
            $completionStore,
            $this->createMock(CheckoutContinueUrlBuilderInterface::class),
            $this->configService(new UcpConfig()),
            $this->createMock(ShopwareDataMapperInterface::class),
            $publisher,
        );

        $subscriber->onOrderStateChanged(new OrderStateMachineStateChangeEvent(
            'state_enter.'.OrderStates::STATE_MACHINE.'.'.OrderStates::STATE_CANCELLED,
            $order,
            Context::createDefaultContext(),
        ));
    }

    private function configService(UcpConfig $config): UcpConfigService
    {
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturn($config);

        return new UcpConfigService($repository, $this->createMock(LegacyConfigStoreInterface::class));
    }
}
