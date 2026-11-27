<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManagerInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Swag\AgenticCommerce\Ucp\Order\OrderPlacedSubscriber;
use Swag\AgenticCommerce\Ucp\Order\OrderWebhookEmitter;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Model\Webhook\WebhookDispatchResult;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/** @internal */
#[CoversClass(OrderPlacedSubscriber::class)]
final class OrderPlacedSubscriberTest extends TestCase
{
    private const CHECKOUT_ID = 'checkout-token';
    private const ROTATED_TOKEN = 'rotated-context-token';
    private const ORDER_ID = '99999999999999999999999999999999';
    private const SALES_CHANNEL_ID = '22222222222222222222222222222222';
    private const DEEP_LINK_CODE = 'sQ3-deep-link-code_9';
    private const LOCK_KEY = 'ucp.checkout.completion.'.self::CHECKOUT_ID.'.'.self::SALES_CHANNEL_ID;

    private ?SalesChannelContext $salesChannelContext = null;

    #[Test]
    public function testSubscribesToCheckoutOrderPlaced(): void
    {
        self::assertSame(
            [CheckoutOrderPlacedEvent::class => 'onOrderPlaced'],
            OrderPlacedSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function testBuyerPlacedOrderRecordsTheCompletionSessionFirst(): void
    {
        $calls = [];

        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::once())
            ->method('saveForCheckoutId')
            ->with(
                self::CHECKOUT_ID,
                static::isInstanceOf(SalesChannelContext::class),
                'completed',
                static::callback(static fn (Buyer $buyer): bool => 'buyer@example.com' === $buyer->email
                    && 'Store' === $buyer->firstName
                    && 'Buyer' === $buyer->lastName),
                [],
                self::ORDER_ID,
                self::DEEP_LINK_CODE,
            )
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'session';
            });

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->with(self::CHECKOUT_ID)->willReturn(null);
        $completionStore->expects(static::once())
            ->method('complete')
            ->with(self::CHECKOUT_ID, self::ORDER_ID)
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'row';
            });

        $mapper = $this->createMock(ShopwareDataMapperInterface::class);
        $mapper->method('toOrderView')->willReturn(new OrderView(self::ORDER_ID, 'EUR', [], []));

        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher->expects(static::once())
            ->method('publish')
            ->with(
                'https://agent.example/webhook',
                static::callback(static fn (OrderWebhookPayload $payload): bool => 'order.created' === $payload->event
                    && self::ORDER_ID === $payload->orderId),
            )
            ->willReturnCallback(static function () use (&$calls): WebhookDispatchResult {
                $calls[] = 'webhook';

                return new WebhookDispatchResult('https://agent.example/webhook', 200, true);
            });

        $subscriber = $this->subscriber(
            $this->sessionMetadata(),
            $sessionManager,
            $completionStore,
            $mapper,
            $publisher,
        );

        $subscriber->onOrderPlaced($this->event($this->order()));

        self::assertSame(['session', 'row', 'webhook'], $calls, 'The session must carry the rotated context token before the completion row exists');
    }

    #[Test]
    public function testAnExplicitCheckoutIdWinsOverTheStoredToken(): void
    {
        // A session saved after token rotation stores a shopwareContextToken that is no longer
        // the checkout id; the explicit key keeps the completion on the right checkout.
        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::once())
            ->method('saveForCheckoutId')
            ->with(self::CHECKOUT_ID, static::isInstanceOf(SalesChannelContext::class), 'completed');
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->with(self::CHECKOUT_ID)->willReturn(null);
        $completionStore->expects(static::once())->method('complete')->with(self::CHECKOUT_ID, self::ORDER_ID);

        $mapper = $this->createMock(ShopwareDataMapperInterface::class);
        $mapper->method('toOrderView')->willReturn(new OrderView(self::ORDER_ID, 'EUR', [], []));
        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher->expects(static::once())->method('publish')
            ->willReturn(new WebhookDispatchResult('https://agent.example/webhook', 200, true));

        $subscriber = $this->subscriber([
            'status' => 'ready_for_complete',
            'shopwareContextToken' => self::ROTATED_TOKEN,
            'ucpCheckoutId' => self::CHECKOUT_ID,
        ], $sessionManager, $completionStore, $mapper, $publisher);

        $subscriber->onOrderPlaced($this->event($this->order()));
    }

    #[Test]
    public function testAgentDrivenCompletionIsSkipped(): void
    {
        $frameworkContext = Context::createDefaultContext();
        $frameworkContext->addState(CheckoutCompleter::AGENT_COMPLETION_STATE);
        $event = $this->event($this->order(), $frameworkContext);

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->expects(static::never())->method('load');

        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::never())->method('saveForCheckoutId');
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->expects(static::never())->method('complete');

        $subscriber = $this->subscriber([], $sessionManager, $completionStore, persister: $persister);

        $subscriber->onOrderPlaced($event);
    }

    #[Test]
    public function testAnInFlightAgentCompletionBacksOff(): void
    {
        $lockFactory = new LockFactory(new InMemoryStore());
        $heldLock = $lockFactory->createLock(self::LOCK_KEY, 300.0);
        self::assertTrue($heldLock->acquire(false));

        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::never())->method('saveForCheckoutId');
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->expects(static::never())->method('completedOrderId');
        $completionStore->expects(static::never())->method('complete');

        $subscriber = $this->subscriber($this->sessionMetadata(), $sessionManager, $completionStore, lockFactory: $lockFactory);

        $subscriber->onOrderPlaced($this->event($this->order()));

        self::assertTrue($heldLock->isAcquired(), 'The completer\'s lock must stay with the completer');
    }

    #[Test]
    public function testAnOrderWithoutAUcpCheckoutIsIgnored(): void
    {
        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::never())->method('saveForCheckoutId');
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->expects(static::never())->method('complete');

        $subscriber = $this->subscriber([], $sessionManager, $completionStore);

        $subscriber->onOrderPlaced($this->event($this->order()));
    }

    #[Test]
    public function testAnAlreadyRecordedCheckoutIsLeftAlone(): void
    {
        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::never())->method('saveForCheckoutId');
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->with(self::CHECKOUT_ID)->willReturn(self::ORDER_ID);
        $completionStore->expects(static::never())->method('complete');

        $subscriber = $this->subscriber($this->sessionMetadata(), $sessionManager, $completionStore);

        $subscriber->onOrderPlaced($this->event($this->order()));
    }

    #[Test]
    public function testASecondOrderOnACompletedSessionIsIgnored(): void
    {
        // After the first completion the rotated token's metadata reads status completed and
        // points at itself; a later order on that session must not write another record.
        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::never())->method('saveForCheckoutId');
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->expects(static::never())->method('completedOrderId');
        $completionStore->expects(static::never())->method('complete');

        $subscriber = $this->subscriber([
            'status' => 'completed',
            'shopwareContextToken' => self::ROTATED_TOKEN,
            'orderId' => self::ORDER_ID,
        ], $sessionManager, $completionStore);

        $subscriber->onOrderPlaced($this->event($this->order()));
    }

    #[Test]
    public function testACanceledCheckoutIsNotCompleted(): void
    {
        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::never())->method('saveForCheckoutId');
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->expects(static::never())->method('complete');

        $subscriber = $this->subscriber($this->sessionMetadata(status: 'canceled'), $sessionManager, $completionStore);

        $subscriber->onOrderPlaced($this->event($this->order()));
    }

    #[Test]
    public function testAFailedRecordingIsLoggedAndDoesNotFailTheOrder(): void
    {
        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->expects(static::once())
            ->method('saveForCheckoutId')
            ->with(
                self::CHECKOUT_ID,
                static::isInstanceOf(SalesChannelContext::class),
                'completed',
                // Without an order customer the agent-supplied session buyer is the fallback.
                static::callback(static fn (Buyer $buyer): bool => 'agent-buyer@example.com' === $buyer->email),
            );

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);
        $completionStore->method('complete')->willThrowException(new \RuntimeException('insert failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())
            ->method('error')
            ->with(
                'Recording the UCP checkout completion failed.',
                static::callback(static fn (array $context): bool => self::ORDER_ID === $context['orderId']
                    && $context['exception'] instanceof \RuntimeException),
            );

        $lockFactory = new LockFactory(new InMemoryStore());
        $order = $this->order(withCustomer: false);
        $subscriber = $this->subscriber($this->sessionMetadata(), $sessionManager, $completionStore, logger: $logger, lockFactory: $lockFactory);

        $subscriber->onOrderPlaced($this->event($order));

        self::assertTrue($lockFactory->createLock(self::LOCK_KEY)->acquire(false), 'The lock must be released after a failed recording');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function subscriber(
        array $metadata,
        CheckoutSessionManagerInterface $sessionManager,
        CheckoutCompletionStoreInterface $completionStore,
        ?ShopwareDataMapperInterface $mapper = null,
        ?OrderWebhookPublisherInterface $publisher = null,
        ?LoggerInterface $logger = null,
        ?SalesChannelContextPersister $persister = null,
        ?LockFactory $lockFactory = null,
    ): OrderPlacedSubscriber {
        if (null === $persister) {
            $persister = $this->createMock(SalesChannelContextPersister::class);
            $persister->method('load')
                ->with(self::ROTATED_TOKEN, self::SALES_CHANNEL_ID, null)
                ->willReturn([] === $metadata ? [] : ['swagAgenticCommerce' => ['ucpCheckout' => $metadata]]);
        }

        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn(new UcpConfig(
            profileDomain: 'https://shop.example',
            webhookUrlOverride: 'https://agent.example/webhook',
            agentAllowlist: ['agent.example'],
        ));

        if (null === $publisher) {
            // Every path that does not record a completion must not publish either.
            $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
            $publisher->expects(static::never())->method('publish');
        }

        // On cores whose event does not carry the SalesChannelContext, the subscriber reads it
        // from the ordering request.
        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $this->salesChannelContext());
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new OrderPlacedSubscriber(
            new CheckoutSessionStore($persister),
            $sessionManager,
            $completionStore,
            new OrderWebhookEmitter(
                new UcpConfigService($configRepository, $this->createMock(LegacyConfigStoreInterface::class)),
                new CheckoutWebhookUrlGuard(new SalesChannelViewProvider(
                    $this->createMock(EntityRepository::class),
                    new StaticSalesChannelTypeResolver(),
                )),
                new OrderPermalinkBuilder(),
                $mapper ?? $this->createMock(ShopwareDataMapperInterface::class),
                $publisher,
            ),
            $lockFactory ?? new LockFactory(new InMemoryStore()),
            $requestStack,
            $logger,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionMetadata(string $status = 'ready_for_complete'): array
    {
        return [
            'status' => $status,
            'buyer' => ['email' => 'agent-buyer@example.com', 'firstName' => 'Agent', 'lastName' => 'Buyer'],
            'shopwareContextToken' => self::CHECKOUT_ID,
        ];
    }

    private function order(bool $withCustomer = true): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setDeepLinkCode(self::DEEP_LINK_CODE);

        if ($withCustomer) {
            $orderCustomer = new OrderCustomerEntity();
            $orderCustomer->setEmail('buyer@example.com');
            $orderCustomer->setFirstName('Store');
            $orderCustomer->setLastName('Buyer');
            $order->setOrderCustomer($orderCustomer);
        }

        return $order;
    }

    /**
     * Builds the event for the core under test: 6.7 takes (SalesChannelContext, OrderEntity),
     * 6.5/6.6 take (Context, OrderEntity, string $salesChannelId, ...) - there the subscriber
     * resolves the context through the request attribute, which these tests then exercise.
     */
    private function event(OrderEntity $order, ?Context $frameworkContext = null): CheckoutOrderPlacedEvent
    {
        $frameworkContext ??= Context::createDefaultContext();
        $salesChannelContext = $this->salesChannelContext($frameworkContext);

        $reflection = new \ReflectionClass(CheckoutOrderPlacedEvent::class);
        $firstParameterType = $reflection->getConstructor()?->getParameters()[0]->getType();
        $arguments = $firstParameterType instanceof \ReflectionNamedType && SalesChannelContext::class === $firstParameterType->getName()
            ? [$salesChannelContext, $order]
            : [$frameworkContext, $order, self::SALES_CHANNEL_ID];

        return $reflection->newInstanceArgs($arguments);
    }

    private function salesChannelContext(?Context $frameworkContext = null): SalesChannelContext
    {
        if (null === $this->salesChannelContext) {
            $salesChannelContext = $this->createMock(SalesChannelContext::class);
            $salesChannelContext->method('getToken')->willReturn(self::ROTATED_TOKEN);
            $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
            $salesChannelContext->method('getContext')->willReturn($frameworkContext ?? Context::createDefaultContext());
            $this->salesChannelContext = $salesChannelContext;
        }

        return $this->salesChannelContext;
    }
}
