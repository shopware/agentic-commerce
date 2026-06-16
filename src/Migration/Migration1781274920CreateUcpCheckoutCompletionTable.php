<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/** @internal */
final class Migration1781274920CreateUcpCheckoutCompletionTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781274920;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_checkout_completion` (
                `checkout_id` VARCHAR(255) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `order_id` BINARY(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`checkout_id`, `sales_channel_id`),
                INDEX `idx.sac_ucp_checkout_completion.order` (`order_id`),
                CONSTRAINT `fk.sac_ucp_checkout_completion.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Keep checkout idempotency records for order replay and auditability.
    }
}
