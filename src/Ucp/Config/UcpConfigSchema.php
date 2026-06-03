<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
final class UcpConfigSchema
{
    public const TABLE = 'swag_agentic_commerce_ucp_config';

    public const CREATE_TABLE_SQL = <<<'SQL'
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
        SQL;

    /**
     * @var \WeakMap<Connection, true>|null
     */
    private static ?\WeakMap $ensuredConnections = null;

    private function __construct()
    {
    }

    public static function ensure(Connection $connection): void
    {
        $ensuredConnections = self::$ensuredConnections;
        if (null === $ensuredConnections) {
            /** @var \WeakMap<Connection, true> $ensuredConnections */
            $ensuredConnections = new \WeakMap();
            self::$ensuredConnections = $ensuredConnections;
        }

        if (isset($ensuredConnections[$connection])) {
            return;
        }

        $connection->executeStatement(self::CREATE_TABLE_SQL);
        $ensuredConnections[$connection] = true;
    }
}
