<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
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
    }

    public function testMigrationCanRunTwice(): void
    {
        // Never drop in tearDown: later suites read these tables, so the schema must be left migrated.
        $this->dropUcpTables();

        foreach (array_keys($this->ucpTablesWithSalesChannelForeignKeys()) as $table) {
            static::assertFalse($this->tableExists($table));
        }

        $migration = new Migration1780328112CreateUcpConfigTable();

        $migration->update($this->connection);
        $migration->update($this->connection);

        foreach ($this->ucpTablesWithSalesChannelForeignKeys() as $table => $foreignKey) {
            static::assertTrue($this->tableExists($table));
            static::assertTrue($this->foreignKeyExists($table, $foreignKey));
            static::assertTrue($this->indexExists($table, 'PRIMARY'));
        }

        static::assertSame(4, $this->columnCount(self::CONFIG_TABLE));
        static::assertSame(11, $this->columnCount(self::OAUTH_CODE_TABLE));
        static::assertSame(8, $this->columnCount(self::OAUTH_ACCESS_TOKEN_TABLE));
        static::assertSame(8, $this->columnCount(self::OAUTH_REFRESH_TOKEN_TABLE));
        static::assertTrue($this->indexExists(self::OAUTH_CODE_TABLE, 'idx.swag_agentic_commerce_ucp_oauth_code.sales_channel'));
        static::assertTrue($this->indexExists(self::OAUTH_ACCESS_TOKEN_TABLE, 'idx.swag_agentic_commerce_ucp_oauth_access.sales_channel'));
        static::assertTrue($this->indexExists(self::OAUTH_ACCESS_TOKEN_TABLE, 'idx.swag_agentic_commerce_ucp_oauth_access.refresh'));
        static::assertTrue($this->indexExists(self::OAUTH_REFRESH_TOKEN_TABLE, 'idx.swag_agentic_commerce_ucp_oauth_refresh.sales_channel'));
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

    private function tableExists(string $table): bool
    {
        return 1 === $this->countSchemaRows(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => $table],
        );
    }

    private function columnCount(string $table): int
    {
        return $this->countSchemaRows(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => $table],
        );
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        return 1 === $this->countSchemaRows(
            <<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                    AND TABLE_NAME = :table
                    AND CONSTRAINT_NAME = :foreignKey
                    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                SQL,
            ['table' => $table, 'foreignKey' => $foreignKey],
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return $this->countSchemaRows(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index',
            ['table' => $table, 'index' => $index],
        ) > 0;
    }

    /**
     * @param array<string, string> $params
     */
    private function countSchemaRows(string $sql, array $params): int
    {
        return (int) $this->connection->fetchOne($sql, $params);
    }
}
