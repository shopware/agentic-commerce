<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Util\Database\TableHelper;
use Swag\AgenticCommerce\Migration\Migration1780328112CreateUcpConfigTable;

/**
 * @internal
 */
#[CoversClass(Migration1780328112CreateUcpConfigTable::class)]
final class Migration1780328112CreateUcpConfigTableTest extends TestCase
{
    private const CONFIG_TABLE = 'swag_agentic_commerce_ucp_config';
    private const OAUTH_CODE_TABLE = 'swag_agentic_commerce_ucp_oauth_code';
    private const OAUTH_ACCESS_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';
    private const OAUTH_REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = KernelLifecycleManager::getConnection();
        $this->dropUcpTables();
    }

    protected function tearDown(): void
    {
        $this->dropUcpTables();
    }

    public function testMigrationCanRunTwice(): void
    {
        foreach (array_keys($this->ucpTablesWithSalesChannelForeignKeys()) as $table) {
            static::assertFalse(TableHelper::tableExists($this->connection, $table));
        }

        $migration = new Migration1780328112CreateUcpConfigTable();

        $migration->update($this->connection);
        $migration->update($this->connection);

        foreach ($this->ucpTablesWithSalesChannelForeignKeys() as $table => $foreignKey) {
            static::assertTrue(TableHelper::tableExists($this->connection, $table));
            static::assertTrue(TableHelper::foreignKeyExists($this->connection, $table, $foreignKey));
            static::assertTrue(TableHelper::indexExists($this->connection, $table, 'PRIMARY'));
        }

        static::assertCount(4, TableHelper::getTable($this->connection, self::CONFIG_TABLE)->columns);
        static::assertCount(11, TableHelper::getTable($this->connection, self::OAUTH_CODE_TABLE)->columns);
        static::assertCount(8, TableHelper::getTable($this->connection, self::OAUTH_ACCESS_TOKEN_TABLE)->columns);
        static::assertCount(8, TableHelper::getTable($this->connection, self::OAUTH_REFRESH_TOKEN_TABLE)->columns);
        static::assertTrue(TableHelper::indexExists($this->connection, self::OAUTH_CODE_TABLE, 'idx.swag_agentic_commerce_ucp_oauth_code.sales_channel'));
        static::assertTrue(TableHelper::indexExists($this->connection, self::OAUTH_ACCESS_TOKEN_TABLE, 'idx.swag_agentic_commerce_ucp_oauth_access.sales_channel'));
        static::assertTrue(TableHelper::indexExists($this->connection, self::OAUTH_ACCESS_TOKEN_TABLE, 'idx.swag_agentic_commerce_ucp_oauth_access.refresh'));
        static::assertTrue(TableHelper::indexExists($this->connection, self::OAUTH_REFRESH_TOKEN_TABLE, 'idx.swag_agentic_commerce_ucp_oauth_refresh.sales_channel'));
    }

    /**
     * @return array<non-empty-string, non-empty-string>
     */
    private function ucpTablesWithSalesChannelForeignKeys(): array
    {
        return [
            self::CONFIG_TABLE => 'fk.swag_agentic_commerce_ucp_config.sales_channel_id',
            self::OAUTH_CODE_TABLE => 'fk.swag_agentic_commerce_ucp_oauth_code.sales_channel_id',
            self::OAUTH_ACCESS_TOKEN_TABLE => 'fk.swag_agentic_commerce_ucp_oauth_access.sales_channel_id',
            self::OAUTH_REFRESH_TOKEN_TABLE => 'fk.swag_agentic_commerce_ucp_oauth_refresh.sales_channel_id',
        ];
    }

    private function dropUcpTables(): void
    {
        $this->connection->executeStatement(\sprintf(
            'DROP TABLE IF EXISTS `%s`, `%s`, `%s`, `%s`',
            self::OAUTH_ACCESS_TOKEN_TABLE,
            self::OAUTH_REFRESH_TOKEN_TABLE,
            self::OAUTH_CODE_TABLE,
            self::CONFIG_TABLE,
        ));
    }
}
