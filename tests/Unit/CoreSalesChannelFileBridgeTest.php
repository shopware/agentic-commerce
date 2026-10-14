<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;

/** @internal */
#[CoversClass(CoreSalesChannelFileBridge::class)]
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
            ->willReturnCallback(static function (string $table, array $data, array $criteria) use (&$updatedFiles): int {
                static::assertSame('sales_channel_file', $table);
                static::assertTrue($data['enabled']);

                $updatedFiles[] = $criteria['file_name'];

                return 0;
            });
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
        static::assertSame($expectedFiles, $updatedFiles);
        static::assertSame($expectedFiles, $insertedFiles);
    }

    public function testItFallsBackToTheBuiltInClassificationWithoutAResolver(): void
    {
        $storefrontChannelId = Uuid::randomHex();
        $feedChannelId = Uuid::randomHex();
        $enabledSalesChannelIds = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
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
            ->method('update')
            ->willReturnCallback(static function (string $table, array $data, array $criteria) use (&$enabledSalesChannelIds): int {
                $enabledSalesChannelIds[] = Uuid::fromBytesToHex($criteria['sales_channel_id']);

                return 1;
            });
        $connection->expects(static::never())->method('insert');

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
        $connection->method('fetchOne')->willReturn(1);
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
            ->method('update')
            ->willReturnCallback(static function (string $table, array $data, array $criteria) use (&$enabledSalesChannelIds): int {
                $enabledSalesChannelIds[] = Uuid::fromBytesToHex($criteria['sales_channel_id']);

                return 1;
            });
        $connection->expects(static::never())->method('insert');

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

    public function testInsertMissingFilesOnlyInsertsAndSkipsARowThatAlreadyExists(): void
    {
        $salesChannelId = Uuid::randomHex();
        $attemptedFiles = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(static::never())->method('update');
        $connection
            ->expects(static::exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$attemptedFiles): int {
                static::assertSame('sales_channel_file', $table);
                static::assertTrue($data['enabled']);
                $attemptedFiles[] = $data['file_name'];

                if ('AGENTS.md' === $data['file_name']) {
                    throw $this->uniqueConstraintViolation();
                }

                return 1;
            });

        $bridge = new CoreSalesChannelFileBridge($connection, new CoreSalesChannelFileFeature([self::class]));

        static::assertSame(['llms.txt'], $bridge->insertMissingFiles($salesChannelId, ['llms.txt', 'AGENTS.md']));
        static::assertSame(['llms.txt', 'AGENTS.md'], $attemptedFiles);
    }

    public function testFileStatesAnswersInTheRequestedSpellingOfAFileName(): void
    {
        $salesChannelId = Uuid::randomHex();

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('fetchAllAssociative')->willReturn([
            ['sales_channel_id' => $salesChannelId, 'file_name' => 'agents.md', 'enabled' => '0'],
            ['sales_channel_id' => $salesChannelId, 'file_name' => 'llms.txt', 'enabled' => '1'],
        ]);

        $bridge = new CoreSalesChannelFileBridge($connection, new CoreSalesChannelFileFeature([self::class]));

        static::assertSame(
            [$salesChannelId => ['AGENTS.md' => false, 'llms.txt' => true]],
            $bridge->fileStates([$salesChannelId], ['llms.txt', 'AGENTS.md']),
        );
    }

    public function testTheReadinessPathsDoNothingWithoutCoreSalesChannelFiles(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('fetchOne');
        $connection->expects(static::never())->method('fetchAllAssociative');
        $connection->expects(static::never())->method('insert');

        $bridge = new CoreSalesChannelFileBridge($connection, new CoreSalesChannelFileFeature(['Shopware\\Core\\Missing\\SalesChannelFileClass']));

        static::assertFalse($bridge->supportsCoreFiles());
        static::assertSame([], $bridge->fileStates([Uuid::randomHex()], ['llms.txt']));
        static::assertSame([], $bridge->insertMissingFiles(Uuid::randomHex(), ['llms.txt']));
    }

    private function uniqueConstraintViolation(): UniqueConstraintViolationException
    {
        $exception = (new \ReflectionClass(UniqueConstraintViolationException::class))->newInstanceWithoutConstructor();
        static::assertInstanceOf(UniqueConstraintViolationException::class, $exception);

        return $exception;
    }
}
