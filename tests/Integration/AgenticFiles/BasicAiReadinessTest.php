<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\AgenticFiles;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessService;
use Swag\AgenticCommerce\AgenticFiles\Readiness\Command\ReadinessEnableCommand;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** @internal */
final class BasicAiReadinessTest extends TestCase
{
    private const UCP_CONFIG_TABLE = 'swag_agentic_commerce_ucp_config';

    private Connection $connection;

    private CommandTester $command;

    private string $storefrontId;

    private string $headlessId;

    protected function setUp(): void
    {
        if (!CoreSalesChannelFileFeature::isAvailableByClass()) {
            static::markTestSkipped('Core does not ship the sales-channel file subsystem here; Basic AI Readiness changes nothing on this version.');
        }

        $container = KernelLifecycleManager::getKernel()->getContainer();
        $connection = $container->get(Connection::class);
        static::assertInstanceOf(Connection::class, $connection);
        $salesChannelRepository = $container->get('sales_channel.repository');
        static::assertInstanceOf(EntityRepository::class, $salesChannelRepository);

        $this->connection = $connection;
        $this->connection->beginTransaction();

        $salesChannelViewProvider = new SalesChannelViewProvider($salesChannelRepository, new SalesChannelTypeResolver($salesChannelRepository));
        $readinessService = new BasicAiReadinessService(
            new CoreSalesChannelFileBridge($this->connection, new CoreSalesChannelFileFeature()),
            $salesChannelViewProvider,
        );
        $this->command = new CommandTester(new ReadinessEnableCommand($readinessService, new SalesChannelResolver($salesChannelViewProvider)));

        $this->storefrontId = $this->salesChannelIdOfType(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
        $this->headlessId = $this->salesChannelIdOfType(Defaults::SALES_CHANNEL_TYPE_API);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testItSwitchesOnLlmsTxtAndAgentsMdForAStorefrontAndAHeadlessChannelWithoutTouchingUcp(): void
    {
        $ucpConfigBefore = $this->ucpConfigRows();

        $this->enable();

        static::assertSame([
            [$this->storefrontId, 'AGENTS.md', 1],
            [$this->storefrontId, 'llms.txt', 1],
            [$this->headlessId, 'AGENTS.md', 1],
            [$this->headlessId, 'llms.txt', 1],
        ], $this->fileRows());
        static::assertSame($ucpConfigBefore, $this->ucpConfigRows(), 'No UCP config may be written or switched on.');
    }

    public function testAFileTheMerchantSwitchedOffStaysOff(): void
    {
        $this->connection->insert('sales_channel_file', [
            'id' => Uuid::randomBytes(),
            'sales_channel_id' => Uuid::fromHexToBytes($this->storefrontId),
            'file_family' => 'agentic',
            'file_name' => 'agents.md',
            'enabled' => 0,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $this->enable();

        static::assertSame([
            [$this->storefrontId, 'agents.md', 0],
            [$this->storefrontId, 'llms.txt', 1],
            [$this->headlessId, 'AGENTS.md', 1],
            [$this->headlessId, 'llms.txt', 1],
        ], $this->fileRows());
        static::assertMatchesRegularExpression('/AGENTS\.md\s+disabled by merchant, left off/', $this->command->getDisplay());
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->enable();
        $rowsAfterFirstRun = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, enabled, updated_at FROM sales_channel_file ORDER BY id
            SQL);

        $this->enable();

        static::assertSame($rowsAfterFirstRun, $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, enabled, updated_at FROM sales_channel_file ORDER BY id
            SQL));
        static::assertStringContainsString('Nothing to change.', $this->command->getDisplay());
    }

    private function enable(): void
    {
        $this->command->execute(['--sales-channel' => [$this->storefrontId, $this->headlessId]], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $this->command->getStatusCode(), $this->command->getDisplay());
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function fileRows(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id, file_name, enabled
                FROM sales_channel_file
                WHERE file_family = 'agentic' AND sales_channel_id IN (:salesChannelIds)
                SQL,
            ['salesChannelIds' => Uuid::fromHexToBytesList([$this->storefrontId, $this->headlessId])],
            ['salesChannelIds' => ArrayParameterType::BINARY],
        );

        $fileRows = array_map(
            static fn (array $row): array => [(string) $row['sales_channel_id'], (string) $row['file_name'], (int) $row['enabled']],
            $rows,
        );
        $order = [$this->storefrontId => 0, $this->headlessId => 1];
        usort($fileRows, static fn (array $a, array $b): int => [$order[$a[0]], strtolower($a[1])] <=> [$order[$b[0]], strtolower($b[1])]);

        return $fileRows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ucpConfigRows(): array
    {
        $tableExists = (bool) $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => self::UCP_CONFIG_TABLE],
        );
        if (!$tableExists) {
            return [];
        }

        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id, config_json, updated_at
            FROM swag_agentic_commerce_ucp_config
            ORDER BY sales_channel_id
            SQL);
    }

    private function salesChannelIdOfType(string $typeId): string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = :typeId ORDER BY created_at LIMIT 1',
            ['typeId' => Uuid::fromHexToBytes($typeId)],
        );

        static::assertIsString($id, 'The test database must have a sales channel of type '.$typeId.'.');

        return $id;
    }
}
