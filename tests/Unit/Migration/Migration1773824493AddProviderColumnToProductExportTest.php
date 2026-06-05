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
use Swag\AgenticCommerce\Migration\Migration1773824493AddProviderColumnToProductExport;

/**
 * @internal
 */
#[CoversClass(Migration1773824493AddProviderColumnToProductExport::class)]
class Migration1773824493AddProviderColumnToProductExportTest extends TestCase
{
    public function testCreationTimestampMatchesClassName(): void
    {
        static::assertSame(1773824493, (new Migration1773824493AddProviderColumnToProductExport())->getCreationTimestamp());
    }

    public function testUpdateAddsProviderColumnWhenMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('fetchOne')
            ->with('SHOW COLUMNS FROM `product_export` LIKE :column', ['column' => 'provider'])
            ->willReturn(false);
        $connection->expects(static::once())
            ->method('executeStatement')
            ->with('ALTER TABLE `product_export` ADD COLUMN `provider` VARCHAR(255) NULL DEFAULT NULL');

        (new Migration1773824493AddProviderColumnToProductExport())->update($connection);
    }

    public function testUpdateIsIdempotentWhenColumnAlreadyExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('fetchOne')
            ->with('SHOW COLUMNS FROM `product_export` LIKE :column', ['column' => 'provider'])
            ->willReturn('provider');
        $connection->expects(static::never())->method('executeStatement');

        (new Migration1773824493AddProviderColumnToProductExport())->update($connection);
    }

    public function testUpdateDestructiveIsNoOp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('executeStatement');
        $connection->expects(static::never())->method('fetchOne');

        (new Migration1773824493AddProviderColumnToProductExport())->updateDestructive($connection);
    }
}
