<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueSource;
use Swag\AgenticCommerce\TestData\Catalogue\ProductPicker;
use Swag\AgenticCommerce\TestData\Command\TestDataCommand;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\Seeder\TestDataSeederInterface;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue\CatalogueFixture;
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

    private MockHandler $handler;

    private string $cacheDirectory;

    /** @var array{publicKey: string, secretKey: non-empty-string} */
    private array $keyPair;

    protected function setUp(): void
    {
        $this->seederCalls = new \ArrayObject();
        $this->handler = new MockHandler();
        $this->cacheDirectory = sys_get_temp_dir().'/swag-ac-test-data-command-'.bin2hex(random_bytes(4));
        $this->keyPair = CatalogueFixture::keyPair();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->cacheDirectory);
    }

    public function testItRefusesToRunInProd(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)], appEnv: 'prod');
        $tester->execute(['--sales-channel' => [self::SHOP_ID]], ['interactive' => false]);

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
        $tester->execute(['--sales-channel' => [self::SHOP_ID, 'headless']], ['interactive' => false]);

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
        $tester->execute([], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertSame(['create Products '.self::HEADLESS_ID], $this->seederCalls->getArrayCopy());
    }

    public function testItFailsWithoutAnyUcpEnabledSalesChannel(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)]);
        $tester->execute([], ['interactive' => false]);

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('No sales channel has UCP enabled', $tester->getDisplay());
        static::assertSame([], $this->seederCalls->getArrayCopy());
    }

    public function testItFailsForAnUnknownSalesChannel(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)]);
        $tester->execute(['--sales-channel' => ['Nope']], ['interactive' => false]);

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
        $tester->execute(['--sales-channel' => [self::SHOP_ID]], ['interactive' => false]);

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
        $tester->execute(['--remove' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertSame(['remove Promotions', 'remove Bundle', 'remove Foundation'], $this->seederCalls->getArrayCopy());
        static::assertMatchesRegularExpression('/Promotions\s+not present/', $display);
        static::assertMatchesRegularExpression('/Bundle\s+removed/', $display);
    }

    public function testWithoutInteractionTheBuiltInProductsAreUsedAndNothingIsFetched(): void
    {
        $seeder = new RecordingSeeder('Products', $this->seederCalls);
        $tester = $this->tester([$seeder]);
        $tester->execute(['--sales-channel' => [self::SHOP_ID]], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertNull($seeder->lastSelection?->catalogue->version);
        static::assertCount(0, $this->handler);
        static::assertStringContainsString('Products: built-in, without images', $tester->getDisplay());
    }

    public function testTheCatalogueOptionDownloadsVerifiesAndPicksWithTheGivenSeed(): void
    {
        $this->queueRelease();
        $seeder = new RecordingSeeder('Products', $this->seederCalls);
        $tester = $this->tester([$seeder]);
        $tester->execute(['--sales-channel' => [self::SHOP_ID], '--catalogue' => true, '--seed' => '5'], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        static::assertSame('1.0.0', $seeder->lastSelection?->catalogue->version);
        static::assertSame(5, $seeder->lastSelection->seed);
        static::assertTrue($seeder->lastSelection->hasImages());
        static::assertStringContainsString('Products: catalogue 1.0.0, seed 5 (repeat the choice with --seed 5)', $tester->getDisplay());
        static::assertStringContainsString(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION.': knitted-bunny', $tester->getDisplay());
    }

    public function testInteractivelyTheCatalogueIsTheDefaultAnswer(): void
    {
        $this->queueRelease();
        $seeder = new RecordingSeeder('Products', $this->seederCalls);
        $tester = $this->tester([$seeder]);
        $tester->setInputs(['']);
        $tester->execute(['--sales-channel' => [self::SHOP_ID]]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        static::assertStringContainsString('Use the product catalogue 1.0.0 with images? It downloads', $tester->getDisplay());
        static::assertSame('1.0.0', $seeder->lastSelection?->catalogue->version);
    }

    public function testDecliningTheCatalogueUsesTheBuiltInProductsWithoutDownloading(): void
    {
        $this->queueRelease();
        $seeder = new RecordingSeeder('Products', $this->seederCalls);
        $tester = $this->tester([$seeder]);
        $tester->setInputs(['no']);
        $tester->execute(['--sales-channel' => [self::SHOP_ID]]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertNull($seeder->lastSelection?->catalogue->version);
        static::assertCount(1, $this->handler, 'Only the index was fetched, not the archive.');
    }

    public function testAnUnreachableCatalogueFallsBackToTheBuiltInProductsWhenAsked(): void
    {
        $this->handler->append(new ConnectException('Could not resolve host', new Request('GET', 'https://example.test/index.json')));
        $seeder = new RecordingSeeder('Products', $this->seederCalls);
        $tester = $this->tester([$seeder]);
        $tester->execute(['--sales-channel' => [self::SHOP_ID]]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('Using the built-in products.', $tester->getDisplay());
        static::assertNull($seeder->lastSelection?->catalogue->version);
    }

    public function testAnUnreachableCatalogueFailsWhenItWasRequested(): void
    {
        $this->handler->append(new ConnectException('Could not resolve host', new Request('GET', 'https://example.test/index.json')));
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)]);
        $tester->execute(['--sales-channel' => [self::SHOP_ID], '--catalogue' => true], ['interactive' => false]);

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('--offline', $tester->getDisplay());
        static::assertSame([], $this->seederCalls->getArrayCopy());
    }

    public function testOfflineAndCatalogueTogetherAndAMalformedSeedAreRejected(): void
    {
        $tester = $this->tester([new RecordingSeeder('Products', $this->seederCalls)]);

        $tester->execute(['--sales-channel' => [self::SHOP_ID], '--catalogue' => true, '--offline' => true], ['interactive' => false]);
        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('not both', $tester->getDisplay());

        $tester->execute(['--sales-channel' => [self::SHOP_ID], '--seed' => 'abc'], ['interactive' => false]);
        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('--seed must be a whole number', $tester->getDisplay());
        static::assertSame([], $this->seederCalls->getArrayCopy());
    }

    private function queueRelease(): void
    {
        $zipPath = CatalogueFixture::writeZip($this->cacheDirectory.'.zip', CatalogueFixture::catalogue());
        $signedIndex = CatalogueFixture::signedIndex($zipPath, $this->keyPair['secretKey']);
        $this->handler->append(new Response(200, [], $signedIndex['index']), new Response(200, [], $signedIndex['signature']), new Response(200, [], (string) file_get_contents($zipPath)));
        unlink($zipPath);
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

        $catalogueSource = new CatalogueSource(new Client(['handler' => HandlerStack::create($this->handler)]), $this->cacheDirectory, 'https://example.test/', [$this->keyPair['publicKey']]);

        return new CommandTester(new TestDataCommand($seeders, $this->salesChannelResolver($nameBySalesChannelId), $ucpConfigService, $appEnv, $catalogueSource, new ProductPicker()));
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
