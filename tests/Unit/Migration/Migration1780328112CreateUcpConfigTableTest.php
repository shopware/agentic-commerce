<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Migration\Migration1780328112CreateUcpConfigTable;

/**
 * @internal
 */
#[CoversClass(Migration1780328112CreateUcpConfigTable::class)]
final class Migration1780328112CreateUcpConfigTableTest extends TestCase
{
    public function testCreationTimestampMatchesClassName(): void
    {
        static::assertSame(1780328112, (new Migration1780328112CreateUcpConfigTable())->getCreationTimestamp());
    }

    public function testUpdateCreatesUcpStateTables(): void
    {
        $executed = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::exactly(4))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$executed): int {
                $executed[] = $sql;

                return 0;
            });

        (new Migration1780328112CreateUcpConfigTable())->update($connection);

        static::assertCount(4, $executed);
        static::assertStringContainsString('CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_config`', $executed[0]);
        static::assertStringContainsString('CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_oauth_code`', $executed[1]);
        static::assertStringContainsString('CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_oauth_access_token`', $executed[2]);
        static::assertStringContainsString('CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_oauth_refresh_token`', $executed[3]);

        foreach ($executed as $sql) {
            static::assertStringContainsString('REFERENCES `sales_channel` (`id`)', $sql);
        }
    }

    public function testUpdateDestructiveIsNoOp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('executeStatement');

        (new Migration1780328112CreateUcpConfigTable())->updateDestructive($connection);
    }
}
