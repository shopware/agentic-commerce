<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Onboarding\Fixtures;

use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;

/**
 * Round-trips configs in memory so a test can assert what the activator
 * persisted, which a mock's expectations would only describe.
 *
 * @internal
 */
final class InMemoryUcpConfigRepository implements UcpConfigRepositoryInterface
{
    /**
     * @param array<string, UcpConfig> $configs
     */
    public function __construct(private array $configs = [])
    {
    }

    public function find(string $salesChannelId): ?UcpConfig
    {
        return $this->configs[$salesChannelId] ?? null;
    }

    public function findMany(array $salesChannelIds): array
    {
        $found = [];
        foreach ($salesChannelIds as $salesChannelId) {
            if (isset($this->configs[$salesChannelId])) {
                $found[$salesChannelId] = $this->configs[$salesChannelId];
            }
        }

        return $found;
    }

    public function save(string $salesChannelId, UcpConfig $config): void
    {
        $this->configs[$salesChannelId] = $config;
    }
}
