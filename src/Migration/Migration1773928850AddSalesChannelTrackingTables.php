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
 * Creates the `sales_channel_tracking_order` and `sales_channel_tracking_customer`
 * tables used by the LLM referral tracking listener.
 *
 * DDL is byte-identical to {@see \Shopware\Core\Migration\V6_7\Migration1773928850AddSalesChannelTrackingTables}
 * in Shopware 6.7.10+. Both `CREATE TABLE` statements are idempotent, so when a
 * merchant later upgrades core and uninstalls this plugin, the native migration
 * short-circuits and the existing tracking data is preserved.
 *
 * @internal
 */
class Migration1773928850AddSalesChannelTrackingTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1773928850;
    }

    public function update(Connection $connection): void
    {
        if ($this->coreShipsTrackingTables()) {
            return;
        }

        if (!$this->tableExists($connection, 'sales_channel_tracking_order')) {
            $connection->executeStatement('
                CREATE TABLE `sales_channel_tracking_order` (
                    `id`               BINARY(16)  NOT NULL,
                    `order_id`         BINARY(16)  NOT NULL,
                    `order_version_id` BINARY(16)  NOT NULL,
                    `sales_channel_id` BINARY(16)  NOT NULL,
                    `created_at`       DATETIME(3) NOT NULL,
                    `updated_at`       DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq.sales_channel_tracking_order.order` (`order_id`, `order_version_id`),
                    KEY `idx.sales_channel_tracking_order.sales_channel_id` (`sales_channel_id`),
                    CONSTRAINT `fk.sc_tracking_order.order_id`
                        FOREIGN KEY (`order_id`, `order_version_id`)
                        REFERENCES `order` (`id`, `version_id`)
                        ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk.sc_tracking_order.sales_channel_id`
                        FOREIGN KEY (`sales_channel_id`)
                        REFERENCES `sales_channel` (`id`)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ');
        }

        if (!$this->tableExists($connection, 'sales_channel_tracking_customer')) {
            $connection->executeStatement('
                CREATE TABLE `sales_channel_tracking_customer` (
                    `id`               BINARY(16)  NOT NULL,
                    `customer_id`      BINARY(16)  NOT NULL,
                    `sales_channel_id` BINARY(16)  NOT NULL,
                    `created_at`       DATETIME(3) NOT NULL,
                    `updated_at`       DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq.sales_channel_tracking_customer.customer` (`customer_id`),
                    KEY `idx.sales_channel_tracking_customer.sales_channel_id` (`sales_channel_id`),
                    CONSTRAINT `fk.sc_tracking_customer.customer_id`
                        FOREIGN KEY (`customer_id`)
                        REFERENCES `customer` (`id`)
                        ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk.sc_tracking_customer.sales_channel_id`
                        FOREIGN KEY (`sales_channel_id`)
                        REFERENCES `sales_channel` (`id`)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function coreShipsTrackingTables(): bool
    {
        return class_exists('Shopware\\Core\\Content\\ProductExport\\Tracking\\SalesChannelTrackingOrderDefinition');
    }

    private function tableExists(Connection $connection, string $tableName): bool
    {
        return false !== $connection->fetchOne('SHOW TABLES LIKE :table', ['table' => $tableName]);
    }
}
