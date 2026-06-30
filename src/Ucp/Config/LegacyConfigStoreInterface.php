<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;

/**
 * Read/write access to the legacy system-config backed UCP settings.
 *
 * Lets the config service depend on a plugin-owned abstraction instead of the
 * concrete Shopware SystemConfigService, so it stays unit-testable without core.
 *
 * @internal
 */
#[Package('framework')]
interface LegacyConfigStoreInterface
{
    public function get(string $key, ?string $salesChannelId): mixed;

    public function set(string $key, mixed $value, ?string $salesChannelId): void;
}
