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
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Migration\Migration1773329152AddAgenticCommerceSalesChannelType;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * @internal
 */
#[CoversClass(Migration1773329152AddAgenticCommerceSalesChannelType::class)]
class Migration1773329152AddAgenticCommerceSalesChannelTypeTest extends TestCase
{
    public function testCreationTimestampMatchesClassName(): void
    {
        static::assertSame(
            1773329152,
            (new Migration1773329152AddAgenticCommerceSalesChannelType())->getCreationTimestamp()
        );
    }

    public function testUpdateInsertsSalesChannelTypeWithSystemAndAvailableTranslations(): void
    {
        $expectedTypeId = Uuid::fromHexToBytes(SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE);
        $systemLanguageId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $deLanguageId = Uuid::randomBytes();

        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('fetchOne')
            ->with('SELECT 1 FROM `sales_channel_type` WHERE `id` = :id', ['id' => $expectedTypeId])
            ->willReturn(false);
        $connection->expects(static::once())
            ->method('fetchAllKeyValue')
            ->willReturn([
                'de-DE' => $deLanguageId,
                'en-GB' => $systemLanguageId,
            ]);
        $connection->expects(static::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $cb) => $cb($connection));

        $insertCalls = [];
        $connection->expects(static::exactly(3))
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $payload) use (&$insertCalls): int {
                $insertCalls[] = ['table' => $table, 'payload' => $payload];

                return 1;
            });

        $connection->expects(static::once())
            ->method('executeStatement')
            ->willReturn(1);

        (new Migration1773329152AddAgenticCommerceSalesChannelType())->update($connection);

        static::assertSame('sales_channel_type', $insertCalls[0]['table']);
        static::assertSame($expectedTypeId, $insertCalls[0]['payload']['id']);
        static::assertSame('regular-sparkle', $insertCalls[0]['payload']['icon_name']);

        static::assertSame('sales_channel_type_translation', $insertCalls[1]['table']);
        static::assertSame($expectedTypeId, $insertCalls[1]['payload']['sales_channel_type_id']);
        static::assertSame($systemLanguageId, $insertCalls[1]['payload']['language_id']);
        static::assertSame('Agentic Commerce', $insertCalls[1]['payload']['name']);
        static::assertSame('shopware AG', $insertCalls[1]['payload']['manufacturer']);
        static::assertSame('Sales channel for agentic commerce platforms', $insertCalls[1]['payload']['description']);

        static::assertSame('sales_channel_type_translation', $insertCalls[2]['table']);
        static::assertSame($deLanguageId, $insertCalls[2]['payload']['language_id']);
        static::assertSame('Verkaufskanal für Agentic-Commerce-Plattformen', $insertCalls[2]['payload']['description']);
    }

    public function testUpdateInsertsSeparateEnglishTranslationWhenSystemLanguageIsNotEnglish(): void
    {
        $expectedTypeId = Uuid::fromHexToBytes(SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE);
        $systemLanguageId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $deLanguageId = Uuid::randomBytes();
        $enLanguageId = Uuid::randomBytes();

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAllKeyValue')->willReturn([
            'de-DE' => $deLanguageId,
            'en-GB' => $enLanguageId,
        ]);
        $connection->method('transactional')->willReturnCallback(static fn (callable $cb) => $cb($connection));

        $insertCalls = [];
        $connection->method('insert')->willReturnCallback(static function (string $table, array $payload) use (&$insertCalls): int {
            $insertCalls[] = ['table' => $table, 'payload' => $payload];

            return 1;
        });

        $connection->method('executeStatement')->willReturn(1);

        (new Migration1773329152AddAgenticCommerceSalesChannelType())->update($connection);

        $languageIds = array_map(static fn (array $call): string => $call['payload']['language_id'] ?? '', \array_slice($insertCalls, 1));

        static::assertContains($systemLanguageId, $languageIds);
        static::assertContains($enLanguageId, $languageIds);
        static::assertContains($deLanguageId, $languageIds);
        static::assertCount(4, $insertCalls);
    }

    public function testUpdateSkipsInsertsWhenSalesChannelTypeAlreadyExists(): void
    {
        $expectedTypeId = Uuid::fromHexToBytes(SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE);

        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('fetchOne')
            ->with('SELECT 1 FROM `sales_channel_type` WHERE `id` = :id', ['id' => $expectedTypeId])
            ->willReturn('1');
        $connection->expects(static::never())->method('fetchAllKeyValue');
        $connection->expects(static::never())->method('transactional');
        $connection->expects(static::never())->method('insert');

        $connection->expects(static::once())
            ->method('executeStatement')
            ->willReturn(0);

        (new Migration1773329152AddAgenticCommerceSalesChannelType())->update($connection);
    }

    public function testUpdateShadowsCoreMigrationAfterInsert(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAllKeyValue')->willReturn([]);
        $connection->method('transactional')->willReturnCallback(static fn (callable $cb) => $cb($connection));
        $connection->method('insert')->willReturn(1);

        $statement = null;
        $params = null;
        $connection->expects(static::once())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $arguments) use (&$statement, &$params): int {
                $statement = $sql;
                $params = $arguments;

                return 1;
            });

        (new Migration1773329152AddAgenticCommerceSalesChannelType())->update($connection);

        static::assertIsString($statement);
        static::assertStringContainsString('INSERT IGNORE INTO `migration`', $statement);
        static::assertSame(
            'Shopware\\Core\\Migration\\V6_7\\Migration1773329152AddAgenticAiSalesChannelType',
            $params['class'] ?? null,
        );
        static::assertSame(1773329152, $params['ts'] ?? null);
    }

    public function testUpdateShadowsCoreMigrationEvenWhenSalesChannelTypeAlreadyExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('1');

        $params = null;
        $connection->expects(static::once())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $arguments) use (&$params): int {
                $params = $arguments;

                return 1;
            });

        (new Migration1773329152AddAgenticCommerceSalesChannelType())->update($connection);

        static::assertSame(
            'Shopware\\Core\\Migration\\V6_7\\Migration1773329152AddAgenticAiSalesChannelType',
            $params['class'] ?? null,
        );
    }

    public function testUpdateDestructiveIsNoOp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('executeStatement');
        $connection->expects(static::never())->method('insert');

        (new Migration1773329152AddAgenticCommerceSalesChannelType())->updateDestructive($connection);
    }
}
