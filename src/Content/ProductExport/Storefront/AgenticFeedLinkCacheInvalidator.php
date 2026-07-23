<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Storefront;

use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Drops the cached feed paths whenever a product export changes, so a newly
 * generated, retargeted or deleted export is reflected on the storefront.
 *
 * @internal
 */
final class AgenticFeedLinkCacheInvalidator implements EventSubscriberInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'product_export.written' => 'invalidate',
            'product_export.deleted' => 'invalidate',
        ];
    }

    public function invalidate(EntityWrittenEvent $event): void
    {
        $this->cache->invalidateTags([AgenticFeedLinkResolver::CACHE_TAG]);
    }
}
