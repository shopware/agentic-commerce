<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[Package('framework')]
final readonly class SystemConfigLegacyConfigStore implements LegacyConfigStoreInterface
{
    public function __construct(
        private SystemConfigService $systemConfigService,
    ) {
    }

    public function get(string $key, ?string $salesChannelId): mixed
    {
        return $this->systemConfigService->get($key, $salesChannelId);
    }

    public function set(string $key, mixed $value, ?string $salesChannelId): void
    {
        $this->systemConfigService->set($key, $value, $salesChannelId);
    }
}
