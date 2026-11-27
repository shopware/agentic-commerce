<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Swag\AgenticCommerce\Ucp\Order\OrderWebhookEmitter;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Model\Webhook\WebhookDispatchResult;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/** @internal */
#[CoversClass(OrderWebhookEmitter::class)]
final class OrderWebhookEmitterTest extends TestCase
{
    private const ORDER_ID = '99999999999999999999999999999999';
    private const SALES_CHANNEL_ID = '22222222222222222222222222222222';
    private const CHECKOUT_ID = 'checkout-id';
    private const DEEP_LINK_CODE = 'sQ3-deep-link-code_9';

    #[Test]
    public function testPublishesTheOrderViewWithPermalinkAndRebuiltRequestContext(): void
    {
        $order = $this->order();

        // The webhook carries the same order URL the completion and the read return:
        // Shopware's order page addressed by deep-link code, which is what core's own order
        // mails link to and the one URL that works whether or not the buyer is logged in.
        $mapper = $this->createMock(ShopwareDataMapperInterface::class);
        $mapper->expects(static::once())
            ->method('toOrderView')
            ->with($order, 'https://shop.example/account/order/'.self::DEEP_LINK_CODE, self::CHECKOUT_ID)
            ->willReturn(new OrderView(self::ORDER_ID, 'EUR', [], []));

        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher->expects(static::once())
            ->method('publish')
            ->with(
                'https://agent.example/webhook',
                static::callback(static fn (OrderWebhookPayload $payload): bool => 'order.created' === $payload->event
                    && self::ORDER_ID === $payload->orderId
                    && self::ORDER_ID === $payload->payload['order']['id']),
                static::callback(static fn (RequestContext $context): bool => 'shop.example' === $context->host
                    && self::SALES_CHANNEL_ID === $context->runtimeConfiguration?->tenantIdentifier),
            )
            ->willReturn(new WebhookDispatchResult('https://agent.example/webhook', 200, true));

        $emitter = new OrderWebhookEmitter(
            $this->configService(new UcpConfig(
                profileDomain: 'https://shop.example',
                webhookUrlOverride: 'https://agent.example/webhook',
                agentAllowlist: ['agent.example'],
            )),
            $this->guard(),
            new OrderPermalinkBuilder(),
            $mapper,
            $publisher,
        );

        $emitter->publish('order.created', $order, self::CHECKOUT_ID);
    }

    #[Test]
    public function testAWebhookUrlOutsideTheAllowlistIsNotPublished(): void
    {
        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher->expects(static::never())->method('publish');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('warning');

        $emitter = new OrderWebhookEmitter(
            $this->configService(new UcpConfig(
                profileDomain: 'https://shop.example',
                webhookUrlOverride: 'https://evil.example/webhook',
                agentAllowlist: ['agent.example'],
            )),
            $this->guard(),
            new OrderPermalinkBuilder(),
            $this->createMock(ShopwareDataMapperInterface::class),
            $publisher,
            $logger,
        );

        $emitter->publish('order.created', $this->order(), self::CHECKOUT_ID);
    }

    #[Test]
    public function testWithoutAWebhookUrlNothingIsPublished(): void
    {
        $mapper = $this->createMock(ShopwareDataMapperInterface::class);
        $mapper->expects(static::never())->method('toOrderView');
        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher->expects(static::never())->method('publish');

        $emitter = new OrderWebhookEmitter(
            $this->configService(new UcpConfig(profileDomain: 'https://shop.example')),
            $this->guard(),
            new OrderPermalinkBuilder(),
            $mapper,
            $publisher,
        );

        $emitter->publish('order.updated', $this->order(), self::CHECKOUT_ID);
    }

    private function guard(): CheckoutWebhookUrlGuard
    {
        return new CheckoutWebhookUrlGuard(new SalesChannelViewProvider(
            $this->createMock(EntityRepository::class),
            new StaticSalesChannelTypeResolver(),
        ));
    }

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setDeepLinkCode(self::DEEP_LINK_CODE);

        return $order;
    }

    private function configService(UcpConfig $config): UcpConfigService
    {
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturn($config);

        return new UcpConfigService($repository, $this->createMock(LegacyConfigStoreInterface::class));
    }
}
