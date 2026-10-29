<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\AgenticFiles;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Swag\AgenticCommerce\Ucp\Config\DoctrineDbalUcpConfigRepository;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;

/** @internal */
#[Package('discovery')]
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
        $this->salesChannelId = $this->createStorefrontSalesChannel();
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

    public function testItPreservesExistingFileSettingsAcrossRepeatedInitializationAndSync(): void
    {
        $this->storeUcpConfig('{"active":true}');
        $bridge = new CoreSalesChannelFileBridge($this->connection, new CoreSalesChannelFileFeature());
        $bridge->enableForSalesChannel($this->salesChannelId);
        $this->setFileSettings(enabled: false);
        $disabledFiles = $this->fileRows();

        $bridge->enableForSalesChannel($this->salesChannelId);
        $this->sync();
        $this->sync();

        static::assertSame($disabledFiles, $this->fileRows());
        static::assertSame(0, $this->enabledFileCount());

        $this->setFileSettings(enabled: true);
        $enabledFiles = $this->fileRows();
        $bridge->enableForSalesChannel($this->salesChannelId);
        $this->sync();

        static::assertSame($enabledFiles, $this->fileRows());
        static::assertSame(self::AGENTIC_FILE_COUNT, $this->enabledFileCount());
    }

    public function testSavingActiveUcpConfigPreservesDisabledFilesAndOverrides(): void
    {
        $this->storeUcpConfig('{"active":true}');
        $service = new UcpConfigService(
            new DoctrineDbalUcpConfigRepository($this->connection),
            $this->createStub(LegacyConfigStoreInterface::class),
            new CoreSalesChannelFileBridge($this->connection, new CoreSalesChannelFileFeature()),
        );
        $service->saveConfig(['active' => true], $this->salesChannelId);
        $this->setFileSettings(enabled: false);
        $disabledFiles = $this->fileRows();

        $service->saveConfig(['active' => true], $this->salesChannelId);
        $service->saveConfig(['active' => true], $this->salesChannelId);

        static::assertSame($disabledFiles, $this->fileRows());
        static::assertSame(0, $this->enabledFileCount());

        $service->saveConfig(['active' => false], $this->salesChannelId);
        $service->saveConfig(['active' => true], $this->salesChannelId);
        static::assertSame($disabledFiles, $this->fileRows());

        $this->setFileSettings(enabled: true);
        $enabledFiles = $this->fileRows();
        $service->saveConfig(['active' => false], $this->salesChannelId);
        $service->saveConfig(['active' => true], $this->salesChannelId);

        static::assertSame($enabledFiles, $this->fileRows());
        static::assertSame(self::AGENTIC_FILE_COUNT, $this->enabledFileCount());
    }

    public function testFirstActivationPreservesExistingDisabledFileAndCreatesMissingDefaults(): void
    {
        $this->storeUcpConfig('{"active":false}');
        $this->connection->insert('sales_channel_file', [
            'id' => Uuid::randomBytes(),
            'sales_channel_id' => Uuid::fromHexToBytes($this->salesChannelId),
            'file_family' => 'agentic',
            'file_name' => 'agents.md',
            'enabled' => 0,
            'template_overrides' => '{"content":"Merchant content"}',
            'created_at' => '2025-01-01 00:00:00.000',
        ]);
        $existingFile = $this->fileRows()[0];
        $service = new UcpConfigService(
            new DoctrineDbalUcpConfigRepository($this->connection),
            $this->createStub(LegacyConfigStoreInterface::class),
            new CoreSalesChannelFileBridge($this->connection, new CoreSalesChannelFileFeature()),
        );

        $service->saveConfig(['active' => true], $this->salesChannelId);

        $files = $this->fileRows();
        static::assertCount(self::AGENTIC_FILE_COUNT, $files);
        static::assertSame(['.well-known/ai-catalog.json', 'agents.md', 'llms.txt'], array_column($files, 'file_name'));
        static::assertSame($existingFile, $files[1]);
        static::assertSame(2, $this->enabledFileCount());
        static::assertNull($files[0]['template_overrides']);
        static::assertNull($files[2]['template_overrides']);
    }

    private function setFileSettings(bool $enabled): void
    {
        $this->connection->update('sales_channel_file', [
            'enabled' => (int) $enabled,
            'template_overrides' => '{"content":"Merchant content"}',
            'updated_at' => '2025-01-01 00:00:00.000',
        ], [
            'sales_channel_id' => Uuid::fromHexToBytes($this->salesChannelId),
            'file_family' => 'agentic',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fileRows(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM sales_channel_file WHERE sales_channel_id = :id AND file_family = :family ORDER BY file_name',
            ['id' => Uuid::fromHexToBytes($this->salesChannelId), 'family' => 'agentic'],
        );
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

    private function createStorefrontSalesChannel(): string
    {
        $salesChannel = $this->connection->fetchAssociative('SELECT * FROM sales_channel LIMIT 1');
        static::assertIsArray($salesChannel, 'The test database must have a sales channel.');
        $id = Uuid::randomHex();
        $salesChannel['id'] = Uuid::fromHexToBytes($id);
        $salesChannel['type_id'] = Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
        $salesChannel['access_key'] = Uuid::randomHex();
        $this->connection->insert('sales_channel', $salesChannel);

        return $id;
    }
}
