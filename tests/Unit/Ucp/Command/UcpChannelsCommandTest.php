<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Swag\AgenticCommerce\Ucp\Command\UcpChannelsCommand;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(UcpChannelsCommand::class)]
class UcpChannelsCommandTest extends TestCase
{
    private const MUSIC_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';
    private const STORE_ID = '0191bbbbbbbb7000bbbbbbbbbbbbbbbb';

    public function testItShowsUcpExposurePerChannel(): void
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('findMany')->willReturn([
            self::MUSIC_ID => new UcpConfig(active: true),
            self::STORE_ID => new UcpConfig(active: false),
        ]);
        $configService = new UcpConfigService($configRepository, $this->createMock(LegacyConfigStoreInterface::class));

        $tester = new CommandTester(new UcpChannelsCommand($this->resolver([
            [self::MUSIC_ID, 'Music'],
            [self::STORE_ID, 'Storefront'],
        ]), $configService));
        $tester->execute([], ['interactive' => false]);

        $display = $tester->getDisplay();
        static::assertSame(0, $tester->getStatusCode());
        static::assertMatchesRegularExpression('/Music.*exposed/', $display);
        static::assertMatchesRegularExpression('/Storefront.*off/', $display);
    }

    public function testItWarnsWhenThereAreNoSalesChannels(): void
    {
        $configService = new UcpConfigService(
            $this->createMock(UcpConfigRepositoryInterface::class),
            $this->createMock(LegacyConfigStoreInterface::class),
        );

        $tester = new CommandTester(new UcpChannelsCommand($this->resolver([]), $configService));
        $tester->execute([], ['interactive' => false]);

        static::assertSame(0, $tester->getStatusCode());
        static::assertStringContainsString('No sales channels found', $tester->getDisplay());
    }

    /**
     * @param list<array{0: string, 1: string}> $channels
     */
    private function resolver(array $channels): SalesChannelResolver
    {
        $entities = [];
        foreach ($channels as [$id, $name]) {
            $entity = new SalesChannelEntity();
            $entity->setId($id);
            $entity->setUniqueIdentifier($id);
            $entity->setName($name);
            $entity->setTypeId('0191cccccccc7000cccccccccccccccc');
            $entities[] = $entity;
        }

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new SalesChannelCollection($entities));

        /** @var EntityRepository<SalesChannelCollection>&\PHPUnit\Framework\MockObject\MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($searchResult);

        return new SalesChannelResolver(new SalesChannelViewProvider(
            $repository,
            new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Storefront),
        ));
    }
}
