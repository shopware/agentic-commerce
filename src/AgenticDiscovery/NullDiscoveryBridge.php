<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticDiscovery;

use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
final class NullDiscoveryBridge implements DiscoveryBridgeInterface
{
    public function isAvailable(): bool
    {
        // 6.5 and 6.6 still install the plugin, but they do not ship the core discovery primitives we bridge to on trunk.
        return false;
    }
}
