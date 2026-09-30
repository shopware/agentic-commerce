<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
trait DeletesExistingIds
{
    /**
     * @param EntityRepository<*> $repository
     * @param list<string> $ids
     */
    private function deleteExisting(EntityRepository $repository, array $ids, Context $context): bool
    {
        $existingIds = self::stringIds($repository->searchIds(new Criteria($ids), $context));
        if ([] === $existingIds) {
            return false;
        }

        $repository->delete(array_map(static fn (string $id): array => ['id' => $id], $existingIds), $context);

        return true;
    }

    /**
     * Only single-column primary keys: a mapping entity returns its ids as arrays, which are dropped.
     *
     * @return list<string>
     */
    private static function stringIds(IdSearchResult $result): array
    {
        return array_values(array_filter($result->getIds(), 'is_string'));
    }

    /**
     * @param EntityRepository<*> $repository
     */
    private function idExists(EntityRepository $repository, string $id, Context $context): bool
    {
        return null !== $repository->searchIds(new Criteria([$id]), $context)->firstId();
    }
}
