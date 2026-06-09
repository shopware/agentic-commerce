<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Tracking;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEvents;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Captures the `referringSalesChannel` query param on storefront requests and
 * persists tracking rows on order/customer inserts.
 *
 * Mirror of {@see \Shopware\Core\Content\ProductExport\Tracking\SalesChannelTrackingListener}
 * in Shopware 6.7.10+. Session key, query param, cache prefix and write shape
 * are kept identical so a session captured under this plugin is still resolved
 * correctly after upgrading to native support.
 *
 * @internal
 */
class SalesChannelTrackingListener implements EventSubscriberInterface
{
    final public const SESSION_KEY_REFERRAL_CODE = 'salesChannelReferralCode';

    final public const QUERY_PARAM = 'referringSalesChannel';

    private const TRACKABLE_TYPE_IDS = [
        SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE,
    ];

    private const CACHE_KEY_PREFIX = 'trackable-sales-channel-';

    /**
     * @param EntityRepository<SalesChannelCollection>                 $salesChannelRepository
     * @param EntityRepository<SalesChannelTrackingOrderCollection>    $salesChannelTrackingOrderRepository
     * @param EntityRepository<SalesChannelTrackingCustomerCollection> $salesChannelTrackingCustomerRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $salesChannelTrackingOrderRepository,
        private readonly EntityRepository $salesChannelTrackingCustomerRepository,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly TagAwareCacheInterface $cache,
        private readonly ShopwareVersionDetector $versionDetector,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => [
                ['storeReferralCode', KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE_POST],
            ],
            EntityWrittenContainerEvent::class => 'createTrackingRecords',
            SalesChannelEvents::SALES_CHANNEL_WRITTEN => 'invalidateTrackableChannelCache',
            SalesChannelEvents::SALES_CHANNEL_DELETED => 'invalidateTrackableChannelCache',
        ];
    }

    public function storeReferralCode(ControllerEvent $event): void
    {
        if ($this->versionDetector->coreShipsTrackingTables()) {
            return;
        }

        $request = $event->getRequest();

        /** @var list<string> $scopes */
        $scopes = $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, []);

        if (!\in_array('storefront', $scopes, true)) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $referralCode = $request->query->get(self::QUERY_PARAM);

        if (!\is_string($referralCode) || !Uuid::isValid($referralCode)) {
            return;
        }

        if (!$this->isTrackableChannel($referralCode)) {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY_REFERRAL_CODE, $referralCode);
    }

    public function createTrackingRecords(EntityWrittenContainerEvent $event): void
    {
        if ($this->versionDetector->coreShipsTrackingTables()) {
            return;
        }

        $orderEvent = $event->getEventByEntityName(OrderDefinition::ENTITY_NAME);
        $customerEvent = $event->getEventByEntityName(CustomerDefinition::ENTITY_NAME);

        if (null === $orderEvent && null === $customerEvent) {
            return;
        }

        $referralCode = $this->resolveReferralCode($event);

        if (null === $referralCode) {
            return;
        }

        if (null !== $orderEvent) {
            $this->trackOrders($orderEvent, $event->getContext(), $referralCode);
        }

        if (null !== $customerEvent) {
            $this->trackCustomers($customerEvent, $event->getContext(), $referralCode);
        }
    }

    public function invalidateTrackableChannelCache(EntityWrittenEvent $event): void
    {
        if ($this->versionDetector->coreShipsTrackingTables()) {
            return;
        }

        $tags = array_map(
            static fn (string $id): string => self::CACHE_KEY_PREFIX.$id,
            $event->getIds(),
        );

        $this->cache->invalidateTags($tags);
    }

    private function resolveReferralCode(EntityWrittenContainerEvent $event): ?string
    {
        if (Defaults::LIVE_VERSION !== $event->getContext()->getVersionId()) {
            return null;
        }

        $request = $this->requestStack->getMainRequest();

        if (null === $request || !$request->hasSession(true)) {
            return null;
        }

        $session = $request->getSession();

        if (!$session->isStarted()) {
            return null;
        }

        $referralCode = $session->get(self::SESSION_KEY_REFERRAL_CODE);

        if (!\is_string($referralCode) || '' === $referralCode) {
            return null;
        }

        return $referralCode;
    }

    private function trackOrders(EntityWrittenEvent $orderEvent, Context $context, string $referralCode): void
    {
        $inserts = $this->filterInserts($orderEvent->getWriteResults());

        if ([] === $inserts) {
            return;
        }

        $data = array_map(static function (EntityWriteResult $result) use ($referralCode): array {
            $pk = $result->getPrimaryKey();

            return [
                'id' => Uuid::randomHex(),
                'orderId' => \is_array($pk) ? (string) $pk['id'] : $pk,
                'orderVersionId' => Defaults::LIVE_VERSION,
                'salesChannelId' => $referralCode,
            ];
        }, $inserts);

        try {
            $this->salesChannelTrackingOrderRepository->upsert($data, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Sales channel tracking: failed to write order tracking record', [
                'exception' => $e->getMessage(),
                'salesChannelId' => $referralCode,
            ]);
        }
    }

    private function trackCustomers(EntityWrittenEvent $customerEvent, Context $context, string $referralCode): void
    {
        $inserts = $this->filterInserts($customerEvent->getWriteResults());

        if ([] === $inserts) {
            return;
        }

        $data = array_map(static function (EntityWriteResult $result) use ($referralCode): array {
            $pk = $result->getPrimaryKey();

            return [
                'id' => Uuid::randomHex(),
                'customerId' => \is_array($pk) ? (string) $pk['id'] : $pk,
                'salesChannelId' => $referralCode,
            ];
        }, $inserts);

        try {
            $this->salesChannelTrackingCustomerRepository->upsert($data, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Sales channel tracking: failed to write customer tracking record', [
                'exception' => $e->getMessage(),
                'salesChannelId' => $referralCode,
            ]);
        }
    }

    /**
     * @param array<EntityWriteResult> $results
     *
     * @return list<EntityWriteResult>
     */
    private function filterInserts(array $results): array
    {
        return array_values(array_filter(
            $results,
            static fn (EntityWriteResult $r): bool => EntityWriteResult::OPERATION_INSERT === $r->getOperation(),
        ));
    }

    private function isTrackableChannel(string $salesChannelId): bool
    {
        $cacheKey = self::CACHE_KEY_PREFIX.$salesChannelId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($salesChannelId): bool {
            $item->tag(self::CACHE_KEY_PREFIX.$salesChannelId);

            $criteria = new Criteria([$salesChannelId]);
            $criteria->addFilter(new EqualsAnyFilter('typeId', self::TRACKABLE_TYPE_IDS));
            $criteria->setLimit(1);

            return $this->salesChannelRepository->searchIds($criteria, Context::createDefaultContext())->getTotal() > 0;
        });
    }
}
