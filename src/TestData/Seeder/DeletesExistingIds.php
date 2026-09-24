<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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
        $existing = $repository->searchIds(new Criteria($ids), $context)->getIds();
        if ([] === $existing) {
            return false;
        }

        $repository->delete(array_map(static fn (string $id): array => ['id' => $id], array_values(array_map('strval', $existing))), $context);

        return true;
    }

    /**
     * @param EntityRepository<*> $repository
     */
    private function idExists(EntityRepository $repository, string $id, Context $context): bool
    {
        return null !== $repository->searchIds(new Criteria([$id]), $context)->firstId();
    }
}
