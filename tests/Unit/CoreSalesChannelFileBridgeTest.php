<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;

/** @internal */
#[CoversClass(CoreSalesChannelFileBridge::class)]
#[Package('discovery')]
final class CoreSalesChannelFileBridgeTest extends TestCase
{
    public function testItInitializesMissingAgenticFilesForSalesChannel(): void
    {
        $salesChannelId = Uuid::randomHex();
        $insertedFiles = [];

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(1, false, false, false);
        $connection->expects(static::never())->method('update');
        $connection
            ->expects(static::exactly(3))
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $data) use (&$insertedFiles): int {
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
        static::assertSame($expectedFiles, $insertedFiles);
    }

    public function testItFallsBackToTheBuiltInClassificationWithoutAResolver(): void
    {
        $storefrontChannelId = Uuid::randomHex();
        $feedChannelId = Uuid::randomHex();
        $enabledSalesChannelIds = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(1, 1, 1, false, false, false);
        $connection->method('fetchAllAssociative')->willReturn([
            [
                'sales_channel_id' => $storefrontChannelId,
                'type_id' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT,
                'config_json' => '{"active":true}',
            ],
            [
                'sales_channel_id' => $feedChannelId,
                'type_id' => Defaults::SALES_CHANNEL_TYPE_PRODUCT_COMPARISON,
                'config_json' => '{"active":true}',
            ],
        ]);
        $connection
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $data) use (&$enabledSalesChannelIds): int {
                $enabledSalesChannelIds[] = Uuid::fromBytesToHex($data['sales_channel_id']);

                return 1;
            });
        $connection->expects(static::never())->method('update');

        $bridge = new CoreSalesChannelFileBridge(
            $connection,
            new CoreSalesChannelFileFeature([self::class]),
        );

        $bridge->syncActiveUcpSalesChannels();

        static::assertSame(array_fill(0, 3, $storefrontChannelId), $enabledSalesChannelIds);
    }

    public function testItLetsTheTypeResolverDecideWhichSalesChannelsToSync(): void
    {
        $storefrontChannelId = Uuid::randomHex();
        $partnerChannelId = Uuid::randomHex();
        $enabledSalesChannelIds = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(1, 1, 1, false, false, false);
        $connection->method('fetchAllAssociative')->willReturn([
            [
                'sales_channel_id' => $storefrontChannelId,
                'type_id' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT,
                'config_json' => '{"active":true}',
            ],
            [
                'sales_channel_id' => $partnerChannelId,
                'type_id' => '9ce0868f406d47d98cfe4b281e62f098',
                'config_json' => '{"active":true}',
            ],
        ]);
        $connection
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $data) use (&$enabledSalesChannelIds): int {
                $enabledSalesChannelIds[] = Uuid::fromBytesToHex($data['sales_channel_id']);

                return 1;
            });
        $connection->expects(static::never())->method('update');

        // Inverted on purpose: the resolver's answer has to win over the stored type id.
        $bridge = new CoreSalesChannelFileBridge(
            $connection,
            new CoreSalesChannelFileFeature([self::class]),
            new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Other, [
                $storefrontChannelId => SalesChannelTypeClassification::ProductComparison,
                $partnerChannelId => SalesChannelTypeClassification::Storefront,
            ]),
        );

        $bridge->syncActiveUcpSalesChannels();

        static::assertSame(array_fill(0, 3, $partnerChannelId), $enabledSalesChannelIds);
    }
}
