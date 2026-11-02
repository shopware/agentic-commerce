<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
interface AgenticFilesCoreBridgeInterface
{
    public function initializeForSalesChannel(string $salesChannelId): void;

    public function syncActiveUcpSalesChannels(): void;
}
