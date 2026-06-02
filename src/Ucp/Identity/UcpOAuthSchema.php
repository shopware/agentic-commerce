<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Doctrine\DBAL\Connection;

final class UcpOAuthSchema
{
    public const CODE_TABLE = 'swag_agentic_commerce_ucp_oauth_code';
    public const ACCESS_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';
    public const REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    /**
     * @var \WeakMap<Connection, true>|null
     */
    private static ?\WeakMap $ensuredConnections = null;

    private function __construct()
    {
    }

    public static function ensure(Connection $connection): void
    {
        self::$ensuredConnections ??= new \WeakMap();

        if (isset(self::$ensuredConnections[$connection])) {
            return;
        }

        $connection->executeStatement(
            \sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
                self::CODE_TABLE,
            ),
        );

        $connection->executeStatement(
            \sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
                self::ACCESS_TOKEN_TABLE,
            ),
        );

        $connection->executeStatement(
            \sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
                self::REFRESH_TOKEN_TABLE,
            ),
        );

        self::$ensuredConnections[$connection] = true;
    }
}
