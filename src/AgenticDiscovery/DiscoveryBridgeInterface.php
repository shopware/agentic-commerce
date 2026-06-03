<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticDiscovery;

use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
interface DiscoveryBridgeInterface
{
    public function isAvailable(): bool;
}
