<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * Sales channels sharing a navigation root share one tree. Ids derive from the root, never from a lookup.
 *
 * @internal
 */
#[Package('framework')]
final class CategoryTreeIds
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(private readonly EntityRepository $salesChannelRepository)
    {
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return list<string>
     */
    public function navigationRootIds(array $salesChannelIds, Context $context): array
    {
        if ([] === $salesChannelIds) {
            return [];
        }

        $rootIds = [];
        foreach ($this->salesChannelRepository->search(new Criteria($salesChannelIds), $context)->getEntities() as $salesChannel) {
            $rootIds[$salesChannel->getNavigationCategoryId()] = true;
        }

        return array_keys($rootIds);
    }

    public static function treeId(string $navigationRootId): string
    {
        return TestDataIds::id('category.tree.'.$navigationRootId);
    }

    public static function categoryId(string $navigationRootId, string $categoryKey): string
    {
        return TestDataIds::id('category.'.$navigationRootId.'.'.$categoryKey);
    }
}
