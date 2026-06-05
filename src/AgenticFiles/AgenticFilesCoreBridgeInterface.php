<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
interface AgenticFilesCoreBridgeInterface
{
    public function enableForSalesChannel(string $salesChannelId): void;

    public function syncActiveUcpSalesChannels(): void;
}
