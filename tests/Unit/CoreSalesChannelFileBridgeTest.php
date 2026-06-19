<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;

/** @internal */
final class CoreSalesChannelFileBridgeTest extends TestCase
{
    public function testItEnablesAllAgenticFilesForSalesChannel(): void
    {
        $salesChannelId = Uuid::randomHex();
        $updatedFiles = [];
        $insertedFiles = [];

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(static::once())
            ->method('fetchOne')
            ->willReturn(1);
        $connection
            ->expects(static::exactly(3))
            ->method('update')
            ->willReturnCallback(function (string $table, array $data, array $criteria) use (&$updatedFiles): int {
                static::assertSame('sales_channel_file', $table);
                static::assertTrue($data['enabled']);

                $updatedFiles[] = $criteria['file_name'];

                return 0;
            });
        $connection
            ->expects(static::exactly(3))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertedFiles): int {
                static::assertSame('sales_channel_file', $table);
                static::assertTrue($data['enabled']);

                $insertedFiles[] = $data['file_name'];

                return 1;
            });

        $bridge = new CoreSalesChannelFileBridge(
            $connection,
            new CoreSalesChannelFileFeature([self::class]),
        );

        $bridge->enableForSalesChannel($salesChannelId);

        $expectedFiles = ['llms.txt', 'agents.md', '.well-known/ai-catalog.json'];
        static::assertSame($expectedFiles, $updatedFiles);
        static::assertSame($expectedFiles, $insertedFiles);
    }
}
