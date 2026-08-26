<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\AgenticFiles;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;

/** @internal */
final class CoreSalesChannelFileBridgeTest extends TestCase
{
    private const CONFIG_TABLE = 'swag_agentic_commerce_ucp_config';

    private const AGENTIC_FILE_COUNT = 3;

    private Connection $connection;

    private string $salesChannelId;

    protected function setUp(): void
    {
        $this->connection = KernelLifecycleManager::getConnection();

        if (!CoreSalesChannelFileFeature::isAvailableByClass()) {
            static::markTestSkipped('Core does not ship the sales-channel file subsystem here; the plugin serves agentic files from its fallback bundle.');
        }

        $this->connection->beginTransaction();
        $this->salesChannelId = $this->storefrontSalesChannelId();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testItEnablesAgenticFilesForATransactionalSalesChannel(): void
    {
        $this->storeUcpConfig('{"active":true}');

        $this->sync();

        static::assertSame(self::AGENTIC_FILE_COUNT, $this->enabledFileCount());
    }

    public function testItSkipsASalesChannelWhoseTypeCannotSell(): void
    {
        $this->changeSalesChannelType(Defaults::SALES_CHANNEL_TYPE_PRODUCT_COMPARISON);
        $this->storeUcpConfig('{"active":true}');

        $this->sync();

        static::assertSame(0, $this->enabledFileCount());
    }

    public function testItIgnoresAnInactiveConfig(): void
    {
        $this->storeUcpConfig('{"active":false}');

        $this->sync();

        static::assertSame(0, $this->enabledFileCount());
    }

    private function sync(): void
    {
        (new CoreSalesChannelFileBridge($this->connection, new CoreSalesChannelFileFeature()))
            ->syncActiveUcpSalesChannels();
    }

    private function storeUcpConfig(string $configJson): void
    {
        $this->connection->delete(self::CONFIG_TABLE, ['sales_channel_id' => Uuid::fromHexToBytes($this->salesChannelId)]);
        $this->connection->insert(self::CONFIG_TABLE, [
            'sales_channel_id' => Uuid::fromHexToBytes($this->salesChannelId),
            'config_json' => $configJson,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    private function changeSalesChannelType(string $typeId): void
    {
        $this->connection->update(
            'sales_channel',
            ['type_id' => Uuid::fromHexToBytes($typeId)],
            ['id' => Uuid::fromHexToBytes($this->salesChannelId)],
        );
    }

    private function enabledFileCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM sales_channel_file WHERE sales_channel_id = :id AND file_family = :family AND enabled = 1',
            ['id' => Uuid::fromHexToBytes($this->salesChannelId), 'family' => 'agentic'],
        );
    }

    private function storefrontSalesChannelId(): string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = :typeId LIMIT 1',
            ['typeId' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)],
        );

        static::assertIsString($id, 'The test database must have a storefront sales channel.');

        return $id;
    }
}
