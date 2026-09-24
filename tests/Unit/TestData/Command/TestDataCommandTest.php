<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\TestData\Command\TestDataCommand;
use Swag\AgenticCommerce\TestData\Seeder\TestDataSeederInterface;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(TestDataCommand::class)]
class TestDataCommandTest extends TestCase
{
    private const SHOP_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';
    private const HEADLESS_ID = '0191bbbbbbbb7000bbbbbbbbbbbbbbbb';

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $seederCalls;

    protected function setUp(): void
    {
        $this->seederCalls = new \ArrayObject();
    }

    public function testItRefusesToRunInProd(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)], appEnv: 'prod');
        $tester->execute(['--sales-channel' => [self::SHOP_ID]]);

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('does not run with APP_ENV=prod', $tester->getDisplay());
        static::assertSame([], $this->seederCalls->getArrayCopy());
    }

    public function testItCreatesInOrderAndReportsSkippedAndPresentGroups(): void
    {
        $tester = $this->tester([
            new RecordingSeeder('Foundation', $this->seederCalls, createdLines: ['Rule: target', 'Groups: Format']),
            new RecordingSeeder('Bundle', $this->seederCalls, unavailableReason: 'SwagCommercial is not installed or not active.'),
            new RecordingSeeder('Promotions', $this->seederCalls, isPresent: true),
            new RecordingSeeder('Products', $this->seederCalls),
        ]);
        $tester->execute(['--sales-channel' => [self::SHOP_ID, 'headless']]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertSame([
            'create Foundation '.self::SHOP_ID.','.self::HEADLESS_ID,
            'create Products '.self::SHOP_ID.','.self::HEADLESS_ID,
        ], $this->seederCalls->getArrayCopy());
        static::assertMatchesRegularExpression('/Foundation\s+created\s+Rule: target/', $display);
        static::assertStringContainsString('Groups: Format', $display);
        static::assertMatchesRegularExpression('/Bundle\s+skipped\s+SwagCommercial is not installed or not active\./', $display);
        static::assertMatchesRegularExpression('/Promotions\s+already present/', $display);
    }

    public function testItDefaultsToTheUcpEnabledSalesChannels(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)], activeUcpChannelIds: [self::HEADLESS_ID]);
        $tester->execute([]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertSame(['create Products '.self::HEADLESS_ID], $this->seederCalls->getArrayCopy());
    }

    public function testItFailsWithoutAnyUcpEnabledSalesChannel(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)]);
        $tester->execute([]);

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('No sales channel has UCP enabled', $tester->getDisplay());
        static::assertSame([], $this->seederCalls->getArrayCopy());
    }

    public function testItFailsForAnUnknownSalesChannel(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)]);
        $tester->execute(['--sales-channel' => ['Nope']]);

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('No sales channel matches "Nope".', $tester->getDisplay());
        static::assertSame([], $this->seederCalls->getArrayCopy());
    }

    public function testItFailsForAnAmbiguousSalesChannelName(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)], nameBySalesChannelId: [self::SHOP_ID => 'Shop', self::HEADLESS_ID => 'Shop']);
        $tester->execute(['--sales-channel' => ['Shop']], ['interactive' => false]);

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('is ambiguous', $tester->getDisplay());
        static::assertSame([], $this->seederCalls->getArrayCopy());
    }

    public function testItStopsAtTheFirstFailingGroupAndPointsToRemove(): void
    {
        $tester = $this->tester([
            new RecordingSeeder('Foundation', $this->seederCalls, failure: new \RuntimeException('Duplicate media file name.')),
            new RecordingSeeder('Products', $this->seederCalls),
        ]);
        $tester->execute(['--sales-channel' => [self::SHOP_ID]]);

        $display = $tester->getDisplay();
        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertSame(['create Foundation '.self::SHOP_ID], $this->seederCalls->getArrayCopy());
        static::assertMatchesRegularExpression('/Foundation\s+failed\s+Duplicate media file name\./', $display);
        static::assertStringContainsString('--remove', $display);
    }

    public function testRemoveRunsInReverseOrderRegardlessOfAvailability(): void
    {
        $tester = $this->tester([
            new RecordingSeeder('Foundation', $this->seederCalls, isPresent: true),
            new RecordingSeeder('Bundle', $this->seederCalls, unavailableReason: 'SwagCommercial is not installed or not active.', isPresent: true),
            new RecordingSeeder('Promotions', $this->seederCalls),
        ]);
        $tester->execute(['--remove' => true]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertSame(['remove Promotions', 'remove Bundle', 'remove Foundation'], $this->seederCalls->getArrayCopy());
        static::assertMatchesRegularExpression('/Promotions\s+not present/', $display);
        static::assertMatchesRegularExpression('/Bundle\s+removed/', $display);
    }

    /**
     * @param list<TestDataSeederInterface> $seeders
     * @param list<string>                  $activeUcpChannelIds
     * @param array<string, string>         $nameBySalesChannelId
     */
    private function tester(array $seeders, array $activeUcpChannelIds = [], string $appEnv = 'dev', array $nameBySalesChannelId = [self::SHOP_ID => 'Shop', self::HEADLESS_ID => 'Headless']): CommandTester
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('findMany')->willReturnCallback(static fn (array $salesChannelIds): array => array_combine(
            $salesChannelIds,
            array_map(static fn (string $salesChannelId): UcpConfig => new UcpConfig(active: \in_array($salesChannelId, $activeUcpChannelIds, true)), $salesChannelIds),
        ));
        $ucpConfigService = new UcpConfigService($configRepository, $this->createMock(LegacyConfigStoreInterface::class));

        return new CommandTester(new TestDataCommand($seeders, $this->salesChannelResolver($nameBySalesChannelId), $ucpConfigService, $appEnv));
    }

    /**
     * @param array<string, string> $nameBySalesChannelId
     */
    private function salesChannelResolver(array $nameBySalesChannelId): SalesChannelResolver
    {
        $salesChannels = [];
        foreach ($nameBySalesChannelId as $id => $name) {
            $salesChannel = new SalesChannelEntity();
            $salesChannel->setId($id);
            $salesChannel->setUniqueIdentifier($id);
            $salesChannel->setName($name);
            $salesChannel->setTypeId('0191cccccccc7000cccccccccccccccc');
            $salesChannels[] = $salesChannel;
        }

        $salesChannelSearchResult = $this->createMock(EntitySearchResult::class);
        $salesChannelSearchResult->method('getEntities')->willReturn(new SalesChannelCollection($salesChannels));

        /** @var EntityRepository<SalesChannelCollection>&\PHPUnit\Framework\MockObject\MockObject $salesChannelRepository */
        $salesChannelRepository = $this->createMock(EntityRepository::class);
        $salesChannelRepository->method('search')->willReturn($salesChannelSearchResult);

        return new SalesChannelResolver(new SalesChannelViewProvider(
            $salesChannelRepository,
            new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Storefront),
        ));
    }
}
