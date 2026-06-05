<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Mirror of {@see \Shopware\Core\Migration\V6_7\Migration1778146739AddSalesChannelTrackingCustomerPrivilege}.
 *
 * Grants `sales_channel_tracking_customer:read` to every existing role that
 * already has `customer.viewer`. Without it, the admin customer list page
 * fails to load because the OneToOne `salesChannelTracking` association added
 * to `customer` by {@see \Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension\CustomerSalesChannelTrackingExtension}
 * is permission-checked by the DAL.
 *
 * @internal
 */
class Migration1778146739AddSalesChannelTrackingCustomerPrivilege extends MigrationStep
{
    final public const NEW_PRIVILEGES = [
        'customer.viewer' => [
            'sales_channel_tracking_customer:read',
        ],
    ];

    public function getCreationTimestamp(): int
    {
        return 1778146739;
    }

    public function update(Connection $connection): void
    {
        if ($this->coreShipsTrackingTables()) {
            return;
        }

        $this->addAdditionalPrivileges($connection, self::NEW_PRIVILEGES);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function coreShipsTrackingTables(): bool
    {
        return class_exists('Shopware\\Core\\Content\\ProductExport\\Tracking\\SalesChannelTrackingOrderDefinition');
    }
}
