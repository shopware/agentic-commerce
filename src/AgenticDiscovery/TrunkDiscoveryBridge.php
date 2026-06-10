<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticDiscovery;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;

#[Package('discovery')]
final class TrunkDiscoveryBridge implements DiscoveryBridgeInterface
{
    public function __construct(
        private readonly ShopwareVersionDetector $versionDetector,
    ) {
    }

    public function isAvailable(): bool
    {
        // Agentic discovery only becomes usable once the 6.7+/trunk product-export provider exists in core.
        return $this->versionDetector->supportsAgenticDiscovery();
    }
}
