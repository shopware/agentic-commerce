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
use Swag\AgenticCommerce\Tests\Unit\UcpSigningKeyServiceTestSigningKeyManager;
use Swag\AgenticCommerce\Tests\Unit\UcpSigningKeyServiceTestTenantRepository;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Swag\AgenticCommerce\Ucp\Command\UcpConfigValidateCommand;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(UcpConfigValidateCommand::class)]
class UcpConfigValidateCommandTest extends TestCase
{
    private const CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';

    public function testAnInactiveChannelIsAdvisoryAndExitsZero(): void
    {
        $tester = new CommandTester($this->command(new UcpConfig(active: false)));
        $status = $tester->execute([], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $status);
        static::assertStringContainsString('INFO', $tester->getDisplay());
        static::assertStringContainsString('inactive', $tester->getDisplay());
    }

    public function testAnActiveChannelWithErrorsExitsNonZero(): void
    {
        // Active but with no signing key and no storefront domain -> two errors.
        $config = new UcpConfig(active: true, enabledCapabilities: ['catalog'], enabledTransports: ['rest']);

        $tester = new CommandTester($this->command($config));
        $status = $tester->execute([], ['interactive' => false]);

        static::assertSame(Command::FAILURE, $status);
        static::assertStringContainsString('ERROR', $tester->getDisplay());
    }

    public function testJsonFormatEmitsParseableFindings(): void
    {
        $tester = new CommandTester($this->command(new UcpConfig(active: false)));
        $tester->execute(['--format' => 'json'], ['interactive' => false]);

        $decoded = json_decode(trim($tester->getDisplay()), true);
        static::assertIsArray($decoded);
        static::assertNotEmpty($decoded);
        static::assertSame('inactive', $decoded[0]['code']);
    }

    public function testUnknownFormatIsRejected(): void
    {
        $tester = new CommandTester($this->command(new UcpConfig(active: false)));
        $status = $tester->execute(['--format' => 'yaml'], ['interactive' => false]);

        static::assertSame(Command::INVALID, $status);
        static::assertStringContainsString('Unknown format', $tester->getDisplay());
    }

    public function testInvalidStoredConfigIsRenderedAsErrorFinding(): void
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')
            ->with(self::CHANNEL_ID)
            ->willThrowException(UcpConfigException::invalidJsonPayload());

        $tester = new CommandTester($this->commandWithRepository($configRepository));
        $status = $tester->execute([], ['interactive' => false]);

        static::assertSame(Command::FAILURE, $status);
        static::assertStringContainsString('ERROR', $tester->getDisplay());
        static::assertStringContainsString('invalid_config', $tester->getDisplay());
        static::assertStringContainsString('Invalid JSON payload.', $tester->getDisplay());
    }

    private function command(UcpConfig $config): UcpConfigValidateCommand
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->with(self::CHANNEL_ID)->willReturn($config);

        return $this->commandWithRepository($configRepository);
    }

    private function commandWithRepository(UcpConfigRepositoryInterface $configRepository): UcpConfigValidateCommand
    {
        $configService = new UcpConfigService($configRepository, $this->createMock(LegacyConfigStoreInterface::class));

        $viewProvider = new SalesChannelViewProvider($this->salesChannelRepository());
        $signingKeyService = new UcpSigningKeyService(
            new UcpSigningKeyServiceTestTenantRepository(),
            new UcpSigningKeyServiceTestSigningKeyManager(),
        );

        return new UcpConfigValidateCommand(
            $viewProvider,
            new SalesChannelResolver($viewProvider),
            $configService,
            $signingKeyService,
            new UcpConfigValidator(),
        );
    }

    /**
     * @return EntityRepository<SalesChannelCollection>
     */
    private function salesChannelRepository(): EntityRepository
    {
        $entity = new SalesChannelEntity();
        $entity->setId(self::CHANNEL_ID);
        $entity->setUniqueIdentifier(self::CHANNEL_ID);
        $entity->setName('Storefront');
        $entity->setTypeId('0191cccccccc7000cccccccccccccccc');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new SalesChannelCollection([$entity]));

        /** @var EntityRepository<SalesChannelCollection>&\PHPUnit\Framework\MockObject\MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($searchResult);

        return $repository;
    }
}
