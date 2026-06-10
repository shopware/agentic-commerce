<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
final class CoreSalesChannelFileFeature
{
    /**
     * @param list<string> $requiredClasses
     */
    public function __construct(
        private readonly array $requiredClasses = [
            'Shopware\\Core\\System\\SalesChannel\\File\\Discovery\\SalesChannelFileDiscovery',
            'Shopware\\Core\\System\\SalesChannel\\File\\Rendering\\SalesChannelFileRenderer',
            'Shopware\\Core\\System\\SalesChannel\\Aggregate\\SalesChannelFile\\SalesChannelFileDefinition',
        ],
    ) {
    }

    public static function isAvailableByClass(): bool
    {
        return (new self())->isAvailable();
    }

    public function isAvailable(): bool
    {
        foreach ($this->requiredClasses as $requiredClass) {
            if (!class_exists($requiredClass)) {
                return false;
            }
        }

        return true;
    }

    public function isDatabaseReady(Connection $connection): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return (bool) $connection->fetchOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => 'sales_channel_file'],
        );
    }
}
