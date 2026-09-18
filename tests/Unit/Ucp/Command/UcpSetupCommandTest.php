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
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Tests\Unit\UcpSigningKeyServiceTestSigningKeyManager;
use Swag\AgenticCommerce\Tests\Unit\UcpSigningKeyServiceTestTenantRepository;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Swag\AgenticCommerce\Ucp\Command\UcpSetupCommand;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(UcpSetupCommand::class)]
class UcpSetupCommandTest extends TestCase
{
    private const CHANNEL_ID = '0191bbbbbbbb7000bbbbbbbbbbbbbbbb';

    public function testDevSetupExposesTheChannelWithItsOwnHostsAndLogOnlySignatures(): void
    {
        $saved = null;
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn(null);
        $configRepository->expects(static::once())->method('save')
            ->willReturnCallback(static function (string $salesChannelId, UcpConfig $config) use (&$saved): void {
                $saved = $config;
            });
        $keys = new UcpSigningKeyServiceTestTenantRepository();

        $tester = new CommandTester($this->command($configRepository, $keys));
        $status = $tester->execute(['--sales-channel' => 'Storefront', '--dev' => true], ['interactive' => false]);
        $display = $tester->getDisplay();

        static::assertSame(Command::SUCCESS, $status, $display);
        static::assertInstanceOf(UcpConfig::class, $saved);
        static::assertTrue($saved->active);
        static::assertSame('log', $saved->signaturePolicy);
        static::assertTrue($saved->idempotencyRequired);
        static::assertSame(['localhost', 'shop.localhost'], $saved->platformAllowlist);
        static::assertSame(['localhost', 'shop.localhost'], $saved->agentAllowlist);
        static::assertSame(['localhost', 'shop.localhost'], $saved->remoteProfileAllowlist);
        static::assertCount(1, $keys->allManagedForTenant(self::CHANNEL_ID), 'A signing key is generated when the channel has none.');
        static::assertStringContainsString('Signing key generated', $display);
        static::assertStringContainsString('http://shop.localhost:8088/.well-known/ucp', $display);
        static::assertStringContainsString('SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE=1', $display);
        static::assertStringContainsString('ucp:dev:request catalog.search', $display);
    }

    public function testProductionSetupIsStrictAndAdmitsOnlyTheNamedPlatforms(): void
    {
        $saved = null;
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn(null);
        $configRepository->method('save')
            ->willReturnCallback(static function (string $salesChannelId, UcpConfig $config) use (&$saved): void {
                $saved = $config;
            });

        $tester = new CommandTester($this->command($configRepository, new UcpSigningKeyServiceTestTenantRepository()));
        $tester->execute([
            '--sales-channel' => 'Storefront',
            '--agent-host' => ['Agent.Example.com'],
            '--capabilities' => 'catalog,cart',
        ], ['interactive' => false]);

        static::assertInstanceOf(UcpConfig::class, $saved);
        static::assertSame('strict', $saved->signaturePolicy);
        static::assertSame(['agent.example.com'], $saved->platformAllowlist);
        static::assertSame(['agent.example.com'], $saved->agentAllowlist);
        static::assertSame(['agent.example.com'], $saved->remoteProfileAllowlist);
        static::assertSame(['catalog', 'cart'], $saved->enabledCapabilities);
        static::assertStringNotContainsString('DEVELOPMENT_MODE', $tester->getDisplay());
    }

    public function testProductionSetupWithoutAnAgentHostSaysSoInsteadOfOpeningTheChannel(): void
    {
        $saved = null;
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn(null);
        $configRepository->method('save')
            ->willReturnCallback(static function (string $salesChannelId, UcpConfig $config) use (&$saved): void {
                $saved = $config;
            });

        $tester = new CommandTester($this->command($configRepository, new UcpSigningKeyServiceTestTenantRepository()));
        $tester->execute(['--sales-channel' => 'Storefront'], ['interactive' => false]);

        static::assertInstanceOf(UcpConfig::class, $saved);
        static::assertSame([], $saved->platformAllowlist, 'Production never gets an implicit allowlist.');
        static::assertStringContainsString('No platform is allowed yet', $tester->getDisplay());
    }

    /**
     * saveConfig() merges a partial payload over the stored config, so a production rerun naming
     * no host used to leave an earlier `--dev` run's `localhost` and own-domain hosts in place --
     * a channel documented as admitting only what `--agent-host` names, still admitting a laptop.
     * The summary printed the carried-over hosts, so the only defence was an operator reading it
     * closely enough to notice.
     */
    public function testProductionSetupClearsTheHostsAnEarlierDevRunLeftBehind(): void
    {
        $saved = null;
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn(UcpConfig::fromArray([
            'active' => true,
            'signaturePolicy' => 'log',
            'platformAllowlist' => ['localhost', 'shop.localhost'],
            'agentAllowlist' => ['localhost', 'shop.localhost'],
        ]));
        $configRepository->method('save')
            ->willReturnCallback(static function (string $salesChannelId, UcpConfig $config) use (&$saved): void {
                $saved = $config;
            });

        $tester = new CommandTester($this->command($configRepository, new UcpSigningKeyServiceTestTenantRepository()));
        $tester->execute(['--sales-channel' => 'Storefront'], ['interactive' => false]);

        static::assertInstanceOf(UcpConfig::class, $saved);
        static::assertSame('strict', $saved->signaturePolicy);
        static::assertSame([], $saved->platformAllowlist, 'the dev hosts do not survive a production rerun');
        static::assertSame([], $saved->agentAllowlist);
        // toRuntimeConfiguration() falls back to platformAllowlist only while this one is empty,
        // so a surviving remote-profile host would override the list just cleared above.
        static::assertSame([], $saved->remoteProfileAllowlist);
    }

    public function testDryRunWritesNothingAndGeneratesNoKey(): void
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn(null);
        $configRepository->expects(static::never())->method('save');
        $keys = new UcpSigningKeyServiceTestTenantRepository();

        $tester = new CommandTester($this->command($configRepository, $keys));
        $status = $tester->execute(['--sales-channel' => 'Storefront', '--dev' => true, '--dry-run' => true], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $status);
        static::assertSame([], $keys->allManagedForTenant(self::CHANNEL_ID));
        static::assertStringContainsString('"signaturePolicy": "log"', $tester->getDisplay());
        static::assertStringContainsString('Dry run', $tester->getDisplay());
    }

    public function testItRejectsAnUnknownCapabilityWithoutPersisting(): void
    {
        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->expects(static::never())->method('save');

        $tester = new CommandTester($this->command($configRepository, new UcpSigningKeyServiceTestTenantRepository()));
        $status = $tester->execute(['--sales-channel' => 'Storefront', '--capabilities' => 'catalog,teleport'], ['interactive' => false]);

        static::assertSame(Command::INVALID, $status);
        static::assertStringContainsString('Unknown capability key(s) teleport', $tester->getDisplay());
    }

    private function command(UcpConfigRepositoryInterface $configRepository, UcpSigningKeyServiceTestTenantRepository $keys): UcpSetupCommand
    {
        $configService = new UcpConfigService($configRepository, $this->createMock(LegacyConfigStoreInterface::class));
        $viewProvider = new SalesChannelViewProvider(
            $this->salesChannelRepository(),
            new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Storefront),
        );

        return new UcpSetupCommand(
            new SalesChannelResolver($viewProvider),
            $viewProvider,
            $configService,
            new UcpSigningKeyService($keys, new UcpSigningKeyServiceTestSigningKeyManager()),
            new UcpConfigValidator(),
        );
    }

    /**
     * @return EntityRepository<SalesChannelCollection>
     */
    private function salesChannelRepository(): EntityRepository
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('0191dddddddd7000dddddddddddddddd');
        $domain->setUniqueIdentifier('0191dddddddd7000dddddddddddddddd');
        $domain->setUrl('http://shop.localhost:8088');
        $domain->setLanguageId('2fbb5fe2e29a4d70aa5854ce7ce3e20b');

        $entity = new SalesChannelEntity();
        $entity->setId(self::CHANNEL_ID);
        $entity->setUniqueIdentifier(self::CHANNEL_ID);
        $entity->setName('Storefront');
        $entity->setTypeId('0191cccccccc7000cccccccccccccccc');
        $entity->setDomains(new SalesChannelDomainCollection([$domain]));

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new SalesChannelCollection([$entity]));

        /** @var EntityRepository<SalesChannelCollection>&\PHPUnit\Framework\MockObject\MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($searchResult);

        return $repository;
    }
}
