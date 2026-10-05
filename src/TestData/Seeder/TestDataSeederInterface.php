<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\PickedProducts;

/**
 * One group of the test data set. The command creates the groups in their configured order and removes
 * them in reverse, so a group may reference ids of any group before it.
 *
 * @internal
 */
#[Package('framework')]
interface TestDataSeederInterface
{
    public function label(): string;

    /**
     * @return ?string why the group cannot be created on this shop, null when it can
     */
    public function unavailableReason(): ?string;

    public function exists(Context $context): bool;

    /**
     * @param list<string> $salesChannelIds
     *
     * @return list<string> what was created, for the report
     */
    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array;

    /**
     * Runs regardless of `unavailableReason()`: data created while a plugin was active must stay removable.
     *
     * @return bool false when nothing of the group was present
     */
    public function remove(Context $context): bool;
}
