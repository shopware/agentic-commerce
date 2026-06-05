<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Tracking;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEvents;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerCollection;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingListener;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * @internal
 */
#[CoversClass(SalesChannelTrackingListener::class)]
class SalesChannelTrackingListenerTest extends TestCase
{
    public function testGetSubscribedEventsRegistersAllHooks(): void
    {
        $events = SalesChannelTrackingListener::getSubscribedEvents();

        static::assertArrayHasKey(KernelEvents::CONTROLLER, $events);
        static::assertArrayHasKey(EntityWrittenContainerEvent::class, $events);
        static::assertArrayHasKey(SalesChannelEvents::SALES_CHANNEL_WRITTEN, $events);
        static::assertArrayHasKey(SalesChannelEvents::SALES_CHANNEL_DELETED, $events);
    }

    public function testStoreReferralCodeIgnoresNonStorefrontScope(): void
    {
        $listener = $this->createListener();

        $request = $this->createStorefrontRequest(scopes: ['api']);
        $request->query->set(SalesChannelTrackingListener::QUERY_PARAM, Uuid::randomHex());

        $listener->storeReferralCode($this->createControllerEvent($request));

        static::assertFalse($request->getSession()->has(SalesChannelTrackingListener::SESSION_KEY_REFERRAL_CODE));
    }

    public function testStoreReferralCodeIgnoresMissingSession(): void
    {
        $listener = $this->createListener();

        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, ['storefront']);
        $request->query->set(SalesChannelTrackingListener::QUERY_PARAM, Uuid::randomHex());

        // No exception expected when no session is attached.
        $listener->storeReferralCode($this->createControllerEvent($request));

        static::assertFalse($request->hasSession());
    }

    public function testStoreReferralCodeIgnoresInvalidUuid(): void
    {
        $listener = $this->createListener();

        $request = $this->createStorefrontRequest();
        $request->query->set(SalesChannelTrackingListener::QUERY_PARAM, 'not-a-uuid');

        $listener->storeReferralCode($this->createControllerEvent($request));

        static::assertFalse($request->getSession()->has(SalesChannelTrackingListener::SESSION_KEY_REFERRAL_CODE));
    }

    public function testStoreReferralCodeIgnoresUntrackableChannel(): void
    {
        $referralCode = Uuid::randomHex();

        /** @var StaticEntityRepository<SalesChannelCollection> $salesChannelRepository */
        $salesChannelRepository = new StaticEntityRepository([[]]);

        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $_key, callable $callback): bool {
                return $callback($this->createMock(ItemInterface::class));
            });

        $listener = $this->createListener(salesChannelRepository: $salesChannelRepository, cache: $cache);

        $request = $this->createStorefrontRequest();
        $request->query->set(SalesChannelTrackingListener::QUERY_PARAM, $referralCode);

        $listener->storeReferralCode($this->createControllerEvent($request));

        static::assertFalse($request->getSession()->has(SalesChannelTrackingListener::SESSION_KEY_REFERRAL_CODE));
    }

    public function testStoreReferralCodeStoresValidTrackableChannel(): void
    {
        $referralCode = Uuid::randomHex();

        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->method('get')->willReturn(true);

        $listener = $this->createListener(cache: $cache);

        $request = $this->createStorefrontRequest();
        $request->query->set(SalesChannelTrackingListener::QUERY_PARAM, $referralCode);

        $listener->storeReferralCode($this->createControllerEvent($request));

        static::assertSame(
            $referralCode,
            $request->getSession()->get(SalesChannelTrackingListener::SESSION_KEY_REFERRAL_CODE),
        );
    }

    public function testCreateTrackingRecordsSkipsNonLiveVersion(): void
    {
        /** @var StaticEntityRepository<SalesChannelTrackingOrderCollection> $orderRepo */
        $orderRepo = new StaticEntityRepository([new SalesChannelTrackingOrderCollection()]);
        /** @var StaticEntityRepository<SalesChannelTrackingCustomerCollection> $customerRepo */
        $customerRepo = new StaticEntityRepository([new SalesChannelTrackingCustomerCollection()]);

        $listener = $this->createListener(
            orderRepository: $orderRepo,
            customerRepository: $customerRepo,
        );

        $context = Context::createDefaultContext()->createWithVersionId(Uuid::randomHex());
        $event = new EntityWrittenContainerEvent($context, new NestedEventCollection(), []);

        $listener->createTrackingRecords($event);

        static::assertCount(0, $orderRepo->upserts);
        static::assertCount(0, $customerRepo->upserts);
    }

    public function testCreateTrackingRecordsSkipsWhenNoMainRequest(): void
    {
        /** @var StaticEntityRepository<SalesChannelTrackingOrderCollection> $orderRepo */
        $orderRepo = new StaticEntityRepository([new SalesChannelTrackingOrderCollection()]);

        $listener = $this->createListener(
            orderRepository: $orderRepo,
            requestStack: new RequestStack(),
        );

        $event = $this->createContainerEvent(OrderDefinition::ENTITY_NAME, [Uuid::randomHex()]);

        $listener->createTrackingRecords($event);

        static::assertCount(0, $orderRepo->upserts);
    }

    public function testCreateTrackingRecordsSkipsWhenSessionHasNoReferral(): void
    {
        /** @var StaticEntityRepository<SalesChannelTrackingOrderCollection> $orderRepo */
        $orderRepo = new StaticEntityRepository([new SalesChannelTrackingOrderCollection()]);

        $request = $this->createStorefrontRequest();
        $stack = new RequestStack();
        $stack->push($request);

        $listener = $this->createListener(
            orderRepository: $orderRepo,
            requestStack: $stack,
        );

        $event = $this->createContainerEvent(OrderDefinition::ENTITY_NAME, [Uuid::randomHex()]);

        $listener->createTrackingRecords($event);

        static::assertCount(0, $orderRepo->upserts);
    }

    public function testCreateTrackingRecordsWritesOrderInserts(): void
    {
        $referralCode = Uuid::randomHex();
        $orderId = Uuid::randomHex();
        $skippedOrderId = Uuid::randomHex();

        $insertResult = new EntityWriteResult(
            ['id' => $orderId],
            [],
            OrderDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_INSERT,
        );
        $updateResult = new EntityWriteResult(
            ['id' => $skippedOrderId],
            [],
            OrderDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_UPDATE,
        );

        $context = Context::createDefaultContext();
        $orderEvent = new EntityWrittenEvent(OrderDefinition::ENTITY_NAME, [$insertResult, $updateResult], $context);
        $event = new EntityWrittenContainerEvent($context, new NestedEventCollection([$orderEvent]), []);

        /** @var StaticEntityRepository<SalesChannelTrackingOrderCollection> $orderRepo */
        $orderRepo = new StaticEntityRepository([new SalesChannelTrackingOrderCollection()]);

        $listener = $this->createListener(
            orderRepository: $orderRepo,
            requestStack: $this->buildRequestStackWithReferral($referralCode),
        );

        $listener->createTrackingRecords($event);

        static::assertCount(1, $orderRepo->upserts);
        $record = $orderRepo->upserts[0][0];
        static::assertSame($orderId, $record['orderId']);
        static::assertSame($referralCode, $record['salesChannelId']);
        static::assertSame(Defaults::LIVE_VERSION, $record['orderVersionId']);
        static::assertTrue(Uuid::isValid($record['id']));
    }

    public function testCreateTrackingRecordsWritesCustomerInserts(): void
    {
        $referralCode = Uuid::randomHex();
        $customerId = Uuid::randomHex();

        /** @var StaticEntityRepository<SalesChannelTrackingCustomerCollection> $customerRepo */
        $customerRepo = new StaticEntityRepository([new SalesChannelTrackingCustomerCollection()]);

        $listener = $this->createListener(
            customerRepository: $customerRepo,
            requestStack: $this->buildRequestStackWithReferral($referralCode),
        );

        $event = $this->createContainerEvent(CustomerDefinition::ENTITY_NAME, [$customerId]);

        $listener->createTrackingRecords($event);

        static::assertCount(1, $customerRepo->upserts);
        $record = $customerRepo->upserts[0][0];
        static::assertSame($customerId, $record['customerId']);
        static::assertSame($referralCode, $record['salesChannelId']);
        static::assertTrue(Uuid::isValid($record['id']));
    }

    public function testRepositoryFailureIsLoggedAsWarning(): void
    {
        $referralCode = Uuid::randomHex();

        // StaticEntityRepository's upsert cannot throw; fall back to a mock for
        // this specific failure-path assertion (same pattern core uses).
        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->method('upsert')->willThrowException(new \RuntimeException('db unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Sales channel tracking: failed to write order tracking record',
                static::callback(static fn (array $ctx): bool => $ctx['salesChannelId'] === $referralCode
                    && 'db unavailable' === $ctx['exception']),
            );

        $listener = $this->createListener(
            orderRepository: $orderRepo,
            logger: $logger,
            requestStack: $this->buildRequestStackWithReferral($referralCode),
        );

        $event = $this->createContainerEvent(OrderDefinition::ENTITY_NAME, [Uuid::randomHex()]);

        $listener->createTrackingRecords($event);
    }

    public function testInvalidateTrackableChannelCacheTagsEachId(): void
    {
        $ids = [Uuid::randomHex(), Uuid::randomHex()];

        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with([
                'trackable-sales-channel-'.$ids[0],
                'trackable-sales-channel-'.$ids[1],
            ]);

        $listener = $this->createListener(cache: $cache);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getIds')->willReturn($ids);

        $listener->invalidateTrackableChannelCache($event);
    }

    /**
     * @param EntityRepository<SalesChannelCollection>|null                 $salesChannelRepository
     * @param EntityRepository<SalesChannelTrackingOrderCollection>|null    $orderRepository
     * @param EntityRepository<SalesChannelTrackingCustomerCollection>|null $customerRepository
     */
    private function createListener(
        ?EntityRepository $salesChannelRepository = null,
        ?EntityRepository $orderRepository = null,
        ?EntityRepository $customerRepository = null,
        ?LoggerInterface $logger = null,
        ?RequestStack $requestStack = null,
        ?TagAwareCacheInterface $cache = null,
        ?ShopwareVersionDetector $versionDetector = null,
    ): SalesChannelTrackingListener {
        $salesChannelRepository ??= new StaticEntityRepository([new SalesChannelCollection()]);
        /** @var StaticEntityRepository<SalesChannelCollection> $salesChannelRepository */
        $orderRepository ??= new StaticEntityRepository([new SalesChannelTrackingOrderCollection()]);
        /** @var StaticEntityRepository<SalesChannelTrackingOrderCollection> $orderRepository */
        $customerRepository ??= new StaticEntityRepository([new SalesChannelTrackingCustomerCollection()]);
        /* @var StaticEntityRepository<SalesChannelTrackingCustomerCollection> $customerRepository */

        return new SalesChannelTrackingListener(
            $salesChannelRepository,
            $orderRepository,
            $customerRepository,
            $logger ?? new NullLogger(),
            $requestStack ?? new RequestStack(),
            $cache ?? $this->createMock(TagAwareCacheInterface::class),
            $versionDetector ?? new ShopwareVersionDetector(),
        );
    }

    /**
     * @param list<string> $scopes
     */
    private function createStorefrontRequest(array $scopes = ['storefront']): Request
    {
        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, $scopes);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function createControllerEvent(Request $request): ControllerEvent
    {
        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn () => new \stdClass(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function buildRequestStackWithReferral(string $referralCode): RequestStack
    {
        $request = $this->createStorefrontRequest();
        $request->getSession()->set(SalesChannelTrackingListener::SESSION_KEY_REFERRAL_CODE, $referralCode);

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    /**
     * @param list<string> $entityIds
     */
    private function createContainerEvent(string $entityName, array $entityIds): EntityWrittenContainerEvent
    {
        $context = Context::createDefaultContext();

        $writeResults = array_map(
            static fn (string $id): EntityWriteResult => new EntityWriteResult(
                $id,
                [],
                $entityName,
                EntityWriteResult::OPERATION_INSERT,
            ),
            $entityIds,
        );

        $writtenEvent = new EntityWrittenEvent($entityName, $writeResults, $context);

        return new EntityWrittenContainerEvent($context, new NestedEventCollection([$writtenEvent]), []);
    }
}
