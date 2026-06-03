<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
interface UcpConfigRepositoryInterface
{
    public function find(string $salesChannelId): ?UcpConfig;

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array<string, UcpConfig>
     */
    public function findMany(array $salesChannelIds): array;

    public function save(string $salesChannelId, UcpConfig $config): void;
}
