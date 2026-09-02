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
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClass;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Swag\AgenticCommerce\Ucp\Command\UcpConfigSetCommand;
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
#[CoversClass(UcpConfigSetCommand::class)]
class UcpConfigSetCommandTest extends TestCase
{
    private const STORE_ID = '0191bbbbbbbb7000bbbbbbbbbbbbbbbb';

    public function testItErrorsWithoutPersistingWhenNoFieldOptionIsGiven(): void
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        // Resolving a channel but passing no field is a usage error, not a
        // silent no-op — nothing must be written.
        $configRepository->expects(static::never())->method('save');
        $configService = new UcpConfigService($configRepository, $this->createMock(LegacyConfigStoreInterface::class));

        $tester = new CommandTester(new UcpConfigSetCommand($configService, $this->resolver()));
        $status = $tester->execute(['--sales-channel' => 'Storefront'], ['interactive' => false]);

        static::assertSame(Command::INVALID, $status);
        static::assertStringContainsString('Nothing to set', $tester->getDisplay());
    }

    public function testItRejectsInvalidSignaturePolicyWithoutPersisting(): void
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->expects(static::never())->method('save');

        $tester = new CommandTester($this->command($configRepository));
        $status = $tester->execute([
            '--sales-channel' => 'Storefront',
            '--signature-policy' => 'warn',
        ], ['interactive' => false]);

        static::assertSame(Command::INVALID, $status);
        static::assertStringContainsString('Invalid --signature-policy value "warn"', $tester->getDisplay());
        static::assertStringContainsString('strict', $tester->getDisplay());
        static::assertStringContainsString('log', $tester->getDisplay());
        static::assertStringContainsString('off', $tester->getDisplay());
    }

    public function testItRejectsInvalidIdempotencyWithoutPersisting(): void
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->expects(static::never())->method('save');

        $tester = new CommandTester($this->command($configRepository));
        $status = $tester->execute([
            '--sales-channel' => 'Storefront',
            '--idempotency' => 'banana',
        ], ['interactive' => false]);

        static::assertSame(Command::INVALID, $status);
        static::assertStringContainsString('Invalid --idempotency value "banana"', $tester->getDisplay());
        static::assertStringContainsString('true', $tester->getDisplay());
        static::assertStringContainsString('false', $tester->getDisplay());
        static::assertStringContainsString('yes', $tester->getDisplay());
        static::assertStringContainsString('no', $tester->getDisplay());
    }

    public function testItPersistsValidIdempotencyValue(): void
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->with(self::STORE_ID)->willReturn(null);
        $configRepository->expects(static::once())
            ->method('save')
            ->with(
                self::STORE_ID,
                static::callback(static function (mixed $config): bool {
                    static::assertInstanceOf(UcpConfig::class, $config);
                    static::assertFalse($config->idempotencyRequired);

                    return true;
                }),
            );

        $tester = new CommandTester($this->command($configRepository));
        $status = $tester->execute([
            '--sales-channel' => 'Storefront',
            '--idempotency' => 'false',
        ], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $status);
        static::assertStringContainsString('"idempotencyRequired": false', $tester->getDisplay());
    }

    private function command(UcpConfigRepositoryInterface $configRepository): UcpConfigSetCommand
    {
        $configService = new UcpConfigService($configRepository, $this->createMock(LegacyConfigStoreInterface::class));

        return new UcpConfigSetCommand($configService, $this->resolver());
    }

    private function resolver(): SalesChannelResolver
    {
        $entity = new SalesChannelEntity();
        $entity->setId(self::STORE_ID);
        $entity->setUniqueIdentifier(self::STORE_ID);
        $entity->setName('Storefront');
        $entity->setTypeId('0191cccccccc7000cccccccccccccccc');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new SalesChannelCollection([$entity]));

        /** @var EntityRepository<SalesChannelCollection>&\PHPUnit\Framework\MockObject\MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($searchResult);

        return new SalesChannelResolver(new SalesChannelViewProvider(
            $repository,
            new StaticSalesChannelTypeResolver(SalesChannelTypeClass::Storefront),
        ));
    }
}
