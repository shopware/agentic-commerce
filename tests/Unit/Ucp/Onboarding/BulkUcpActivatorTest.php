<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Onboarding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Onboarding\Fixtures\InMemoryUcpConfigRepository;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Config\Validation\Finding;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;
use Swag\AgenticCommerce\Ucp\Onboarding\BulkUcpActivator;
use Swag\AgenticCommerce\Ucp\Onboarding\ChannelActivationOutcome;
use Swag\AgenticCommerce\Ucp\Onboarding\ChannelFindingsResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/**
 * UcpConfigService, UcpSigningKeyService and SalesChannelViewProvider are final, so they are
 * built for real over mocked leaf dependencies rather than reflection-faked.
 *
 * @internal
 */
#[CoversClass(BulkUcpActivator::class)]
final class BulkUcpActivatorTest extends TestCase
{
    private const STOREFRONT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HEADLESS_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const FEED_ID = 'cccccccccccccccccccccccccccccccc';

    private const CAPABILITIES = ['catalog', 'cart', 'checkout'];
    private const TRANSPORTS = ['rest'];

    private InMemoryUcpConfigRepository $configRepository;

    protected function setUp(): void
    {
        $this->configRepository = new InMemoryUcpConfigRepository();
    }

    public function testItActivatesAnUnexposedChannelAndReportsItsReadiness(): void
    {
        $outcomes = $this->activator()->activate([self::STOREFRONT_ID], self::CAPABILITIES, self::TRANSPORTS, Context::createDefaultContext());

        self::assertCount(1, $outcomes);
        self::assertTrue($outcomes[0]->isEnabled());
        self::assertSame('Storefront', $outcomes[0]->salesChannelName);

        $stored = $this->configRepository->find(self::STOREFRONT_ID);
        self::assertInstanceOf(UcpConfig::class, $stored);
        self::assertTrue($stored->active);
        self::assertSame(self::CAPABILITIES, $stored->enabledCapabilities);
        self::assertSame(self::TRANSPORTS, $stored->enabledTransports);
    }

    public function testItLeavesTheProfileDomainAloneSoEachChannelServesItsOwn(): void
    {
        $this->configRepository->save(self::STOREFRONT_ID, UcpConfig::fromArray([
            'active' => false,
            'profileDomain' => 'https://pinned.example.com',
        ]));

        $this->activator()->activate([self::STOREFRONT_ID], self::CAPABILITIES, self::TRANSPORTS, Context::createDefaultContext());

        $stored = $this->configRepository->find(self::STOREFRONT_ID);
        self::assertInstanceOf(UcpConfig::class, $stored);
        self::assertSame('https://pinned.example.com', $stored->profileDomain);
    }

    public function testItSkipsAChannelThatAlreadyExposesTheRequestedSet(): void
    {
        $this->configRepository->save(self::STOREFRONT_ID, UcpConfig::fromArray([
            'active' => true,
            'enabledCapabilities' => array_reverse(self::CAPABILITIES),
            'enabledTransports' => self::TRANSPORTS,
        ]));

        $outcomes = $this->activator()->activate([self::STOREFRONT_ID], self::CAPABILITIES, self::TRANSPORTS, Context::createDefaultContext());

        self::assertTrue($outcomes[0]->isSkipped());
        self::assertSame(ChannelActivationOutcome::REASON_ALREADY_ACTIVE, $outcomes[0]->reason);
    }

    public function testItRewritesAnActiveChannelWhenTheRequestedSetDiffers(): void
    {
        $this->configRepository->save(self::STOREFRONT_ID, UcpConfig::fromArray([
            'active' => true,
            'enabledCapabilities' => ['catalog'],
            'enabledTransports' => self::TRANSPORTS,
        ]));

        $outcomes = $this->activator()->activate([self::STOREFRONT_ID], self::CAPABILITIES, self::TRANSPORTS, Context::createDefaultContext());

        self::assertTrue($outcomes[0]->isEnabled());
        $stored = $this->configRepository->find(self::STOREFRONT_ID);
        self::assertInstanceOf(UcpConfig::class, $stored);
        self::assertSame(self::CAPABILITIES, $stored->enabledCapabilities);
    }

    public function testARefusedChannelBecomesItsOwnFailureAndTheBatchContinues(): void
    {
        $outcomes = $this->activator()->activate(
            [self::FEED_ID, self::STOREFRONT_ID, self::HEADLESS_ID],
            self::CAPABILITIES,
            self::TRANSPORTS,
            Context::createDefaultContext(),
        );

        self::assertTrue($outcomes[0]->isFailed());
        self::assertSame(UcpConfigException::SALES_CHANNEL_TYPE_NOT_SUPPORTED, $outcomes[0]->code);
        self::assertNull($this->configRepository->find(self::FEED_ID));

        self::assertTrue($outcomes[1]->isEnabled());
        self::assertTrue($outcomes[2]->isEnabled());
    }

    public function testItReportsThatAChannelWithoutADomainCannotBeReached(): void
    {
        $outcomes = $this->activator()->activate([self::HEADLESS_ID], self::CAPABILITIES, self::TRANSPORTS, Context::createDefaultContext());

        self::assertTrue($outcomes[0]->isEnabled());
        $codes = array_map(static fn (Finding $finding): string => $finding->code, $outcomes[0]->findings);
        self::assertContains('no_storefront_domain', $codes);
    }

    private function activator(): BulkUcpActivator
    {
        $typeResolver = new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Other, [
            self::STOREFRONT_ID => SalesChannelTypeClassification::Storefront,
            self::HEADLESS_ID => SalesChannelTypeClassification::Headless,
            self::FEED_ID => SalesChannelTypeClassification::AgenticCommerce,
        ]);

        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturn(null);

        $configService = new UcpConfigService(
            $this->configRepository,
            $legacyStore,
            null,
            null,
            false,
            $typeResolver,
            null,
        );

        return new BulkUcpActivator(
            new SalesChannelViewProvider($this->salesChannelRepository(), $typeResolver),
            $configService,
            new ChannelFindingsResolver(
                new UcpConfigValidator(),
                new UcpSigningKeyService(
                    $this->createMock(ManagedSigningKeyRepositoryInterface::class),
                    $this->createMock(SigningKeyManagerInterface::class),
                ),
            ),
        );
    }

    /**
     * @return EntityRepository<SalesChannelCollection>
     */
    private function salesChannelRepository(): EntityRepository
    {
        $channels = new SalesChannelCollection([
            $this->salesChannel(self::STOREFRONT_ID, 'Storefront', Defaults::SALES_CHANNEL_TYPE_STOREFRONT, 'https://shop.example.com'),
            $this->salesChannel(self::HEADLESS_ID, 'Headless', Defaults::SALES_CHANNEL_TYPE_API, null),
            $this->salesChannel(self::FEED_ID, 'Feed', Defaults::SALES_CHANNEL_TYPE_PRODUCT_COMPARISON, null),
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel',
                $channels->count(),
                $channels,
                null,
                $criteria,
                $context,
            ),
        );

        return $repository;
    }

    private function salesChannel(string $id, string $name, string $typeId, ?string $domainUrl): SalesChannelEntity
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setUniqueIdentifier($id);
        $salesChannel->setId($id);
        $salesChannel->setName($name);
        $salesChannel->setTypeId($typeId);
        $salesChannel->setActive(true);

        $domains = new SalesChannelDomainCollection();
        if (null !== $domainUrl) {
            $domain = new SalesChannelDomainEntity();
            $domain->setUniqueIdentifier($id.'-domain');
            $domain->setId($id.'-domain');
            $domain->setUrl($domainUrl);
            $domain->setLanguageId(Defaults::LANGUAGE_SYSTEM);
            $domains->add($domain);
        }
        $salesChannel->setDomains($domains);

        return $salesChannel;
    }
}
