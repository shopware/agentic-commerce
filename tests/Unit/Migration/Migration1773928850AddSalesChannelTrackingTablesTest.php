<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Migration\Migration1773928850AddSalesChannelTrackingTables;

/**
 * @internal
 */
#[CoversClass(Migration1773928850AddSalesChannelTrackingTables::class)]
class Migration1773928850AddSalesChannelTrackingTablesTest extends TestCase
{
    public function testCreationTimestampMatchesClassName(): void
    {
        static::assertSame(
            1773928850,
            (new Migration1773928850AddSalesChannelTrackingTables())->getCreationTimestamp(),
        );
    }

    public function testUpdateCreatesBothTablesWhenMissing(): void
    {
        $this->skipIfCoreShipsTrackingTables();

        $connection = $this->createMock(Connection::class);

        $connection->expects(static::exactly(2))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $params): bool {
                static::assertSame('SHOW TABLES LIKE :table', $sql);
                static::assertContains(
                    $params['table'],
                    ['sales_channel_tracking_order', 'sales_channel_tracking_customer'],
                );

                return false;
            });

        $executed = [];
        $connection->expects(static::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$executed): int {
                $executed[] = $sql;

                return 0;
            });

        (new Migration1773928850AddSalesChannelTrackingTables())->update($connection);

        static::assertCount(2, $executed);
        static::assertStringContainsString('CREATE TABLE `sales_channel_tracking_order`', $executed[0]);
        static::assertStringContainsString('CREATE TABLE `sales_channel_tracking_customer`', $executed[1]);
        static::assertStringContainsString('REFERENCES `order`', $executed[0]);
        static::assertStringContainsString('REFERENCES `customer`', $executed[1]);
    }

    public function testUpdateIsIdempotentWhenTablesExist(): void
    {
        $this->skipIfCoreShipsTrackingTables();

        $connection = $this->createMock(Connection::class);

        $connection->expects(static::exactly(2))
            ->method('fetchOne')
            ->willReturn('1');

        $connection->expects(static::never())->method('executeStatement');

        (new Migration1773928850AddSalesChannelTrackingTables())->update($connection);
    }

    public function testUpdateCreatesOnlyMissingTable(): void
    {
        $this->skipIfCoreShipsTrackingTables();

        $connection = $this->createMock(Connection::class);

        $connection->expects(static::exactly(2))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $params): bool|string {
                return 'sales_channel_tracking_order' === $params['table'] ? '1' : false;
            });

        $connection->expects(static::once())
            ->method('executeStatement')
            ->with(static::stringContains('CREATE TABLE `sales_channel_tracking_customer`'));

        (new Migration1773928850AddSalesChannelTrackingTables())->update($connection);
    }

    public function testUpdateDestructiveIsNoOp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('executeStatement');
        $connection->expects(static::never())->method('fetchOne');

        (new Migration1773928850AddSalesChannelTrackingTables())->updateDestructive($connection);
    }

    public function testUpdateIsNoOpWhenCoreShipsTrackingTables(): void
    {
        $this->skipUnlessCoreShipsTrackingTables();

        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('fetchOne');
        $connection->expects(static::never())->method('executeStatement');

        (new Migration1773928850AddSalesChannelTrackingTables())->update($connection);
    }

    private function skipIfCoreShipsTrackingTables(): void
    {
        if ((new ShopwareVersionDetector())->coreShipsTrackingTables()) {
            $this->markTestSkipped('Core ships tracking tables; migration delegates to core.');
        }
    }

    private function skipUnlessCoreShipsTrackingTables(): void
    {
        if (!(new ShopwareVersionDetector())->coreShipsTrackingTables()) {
            $this->markTestSkipped('Only applies when core ships tracking tables.');
        }
    }
}
