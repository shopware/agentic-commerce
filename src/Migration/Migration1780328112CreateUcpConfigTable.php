<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1780328112CreateUcpConfigTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1780328112;
    }

    public function update(Connection $connection): void
    {
        // The historical class name only mentions config, but this migration is
        // the first UCP state migration and owns both config and OAuth tables.
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_config` (
                `sales_channel_id` BINARY(16) NOT NULL,
                `config_json` LONGTEXT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`sales_channel_id`),
                CONSTRAINT `fk.swag_agentic_commerce_ucp_config.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_oauth_code` (
                `code_hash` BINARY(32) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `client_id` VARCHAR(512) NOT NULL,
                `redirect_uri` VARCHAR(1024) NOT NULL,
                `subject` VARCHAR(255) NOT NULL,
                `scope` VARCHAR(1024) NOT NULL,
                `code_challenge` VARCHAR(255) NOT NULL,
                `code_challenge_method` VARCHAR(16) NOT NULL,
                `expires_at` INT NOT NULL,
                `consumed_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`code_hash`),
                INDEX `idx.swag_agentic_commerce_ucp_oauth_code.sales_channel` (`sales_channel_id`),
                CONSTRAINT `fk.swag_agentic_commerce_ucp_oauth_code.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_oauth_access_token` (
                `token_hash` BINARY(32) NOT NULL,
                `refresh_token_hash` BINARY(32) NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `client_id` VARCHAR(512) NOT NULL,
                `subject` VARCHAR(255) NOT NULL,
                `scope` VARCHAR(1024) NOT NULL,
                `expires_at` INT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`token_hash`),
                INDEX `idx.swag_agentic_commerce_ucp_oauth_access.sales_channel` (`sales_channel_id`),
                INDEX `idx.swag_agentic_commerce_ucp_oauth_access.refresh` (`refresh_token_hash`),
                CONSTRAINT `fk.swag_agentic_commerce_ucp_oauth_access.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_oauth_refresh_token` (
                `token_hash` BINARY(32) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `client_id` VARCHAR(512) NOT NULL,
                `subject` VARCHAR(255) NOT NULL,
                `scope` VARCHAR(1024) NOT NULL,
                `expires_at` INT NOT NULL,
                `revoked_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`token_hash`),
                INDEX `idx.swag_agentic_commerce_ucp_oauth_refresh.sales_channel` (`sales_channel_id`),
                CONSTRAINT `fk.swag_agentic_commerce_ucp_oauth_refresh.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Keep merchant configuration and OAuth state unless a future explicit
        // uninstall/data-retention policy decides to purge it.
    }
}
