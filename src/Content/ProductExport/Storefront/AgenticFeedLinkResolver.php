<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Storefront;

use Shopware\Core\Content\ProductExport\ProductExportCollection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Swag\AgenticCommerce\Content\ProductExport\Provider\GoogleProductExportProvider;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Resolves the public store-api feed path of the agentic product export bound to a
 * storefront sales channel, so it can be advertised for agent discovery.
 *
 * Only the Google/XML export is returned: it is the format agentic scanners parse.
 * The JSONL (OpenAI) export targets OpenAI's own ingestion and is not a discoverable feed.
 *
 * @internal
 */
final class AgenticFeedLinkResolver
{
    public const CACHE_TAG = 'swag-agentic-commerce.agentic-feed-link';

    private const CACHE_KEY_PREFIX = 'swag-agentic-commerce.agentic-feed-link.';

    /**
     * @param EntityRepository<ProductExportCollection> $productExportRepository
     */
    public function __construct(
        private readonly EntityRepository $productExportRepository,
        private readonly ?TagAwareCacheInterface $cache = null,
    ) {
    }

    /**
     * Returns the store-api feed path (leading slash, host-relative) for the given
     * storefront sales channel, or null when no generated Google/XML export exists.
     */
    public function resolveFeedPath(string $storefrontSalesChannelId): ?string
    {
        if ('' === $storefrontSalesChannelId) {
            return null;
        }

        if (null === $this->cache) {
            return $this->search($storefrontSalesChannelId);
        }

        return $this->cache->get(
            self::CACHE_KEY_PREFIX.$storefrontSalesChannelId,
            function (ItemInterface $item) use ($storefrontSalesChannelId): ?string {
                $item->tag(self::CACHE_TAG);

                return $this->search($storefrontSalesChannelId);
            },
        );
    }

    private function search(string $storefrontSalesChannelId): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('storefrontSalesChannelId', $storefrontSalesChannelId));
        $criteria->addFilter(new EqualsFilter('provider', GoogleProductExportProvider::TECHNICAL_NAME));
        // Only advertise an export whose file has actually been generated.
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('generatedAt', null)]));
        $criteria->addSorting(new FieldSorting('generatedAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $export = $this->productExportRepository
            ->search($criteria, Context::createDefaultContext())
            ->first();

        if (!$export instanceof ProductExportEntity) {
            return null;
        }

        return \sprintf(
            '/store-api/product-export/%s/%s',
            $export->getAccessKey(),
            $export->getFileName(),
        );
    }
}
