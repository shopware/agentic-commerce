<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\System\SalesChannel;

use Shopware\Core\Framework\Log\Package;

/**
 * Resolves what a sales channel *is*, so features can decide what they offer on
 * it. An unresolved channel is {@see SalesChannelTypeClassification::Other} and every
 * feature gating on it stays closed.
 *
 * @internal
 */
#[Package('discovery')]
abstract class AbstractSalesChannelTypeResolver
{
    abstract public function getDecorated(): self;

    abstract public function resolve(string $salesChannelId): SalesChannelTypeClassification;

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array<string, SalesChannelTypeClassification>
     */
    abstract public function resolveMany(array $salesChannelIds): array;
}
