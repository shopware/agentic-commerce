<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
interface AgenticFilesCoreBridgeInterface
{
    /**
     * Initialize missing files as enabled defaults, preserving existing configurations.
     */
    public function enableForSalesChannel(string $salesChannelId): void;

    public function syncActiveUcpSalesChannels(): void;
}
