<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[Package('framework')]
final class SalesChannelDomainResolverCacheInvalidator implements EventSubscriberInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sales_channel_domain.written' => 'invalidate',
            'sales_channel_domain.deleted' => 'invalidate',
        ];
    }

    public function invalidate(EntityWrittenEvent $event): void
    {
        $this->cache->invalidateTags([SalesChannelDomainResolver::CACHE_TAG]);
    }
}
