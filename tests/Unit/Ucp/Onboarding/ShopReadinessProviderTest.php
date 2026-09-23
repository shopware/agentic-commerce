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
use Swag\AgenticCommerce\Tests\Unit\Ucp\Onboarding\Fixtures\StaticOnboardingMetrics;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;
use Swag\AgenticCommerce\Ucp\Onboarding\ChannelFindingsResolver;
use Swag\AgenticCommerce\Ucp\Onboarding\ChannelReadiness;
use Swag\AgenticCommerce\Ucp\Onboarding\OnboardingMetricsInterface;
use Swag\AgenticCommerce\Ucp\Onboarding\ShopReadiness;
use Swag\AgenticCommerce\Ucp\Onboarding\ShopReadinessProvider;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/**
 * UcpConfigService, UcpSigningKeyService and SalesChannelViewProvider are final, so they are
 * built for real over mocked leaf dependencies rather than reflection-faked.
 *
 * @internal
 */
#[CoversClass(ShopReadinessProvider::class)]
#[CoversClass(ShopReadiness::class)]
final class ShopReadinessProviderTest extends TestCase
{
    private const STOREFRONT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HEADLESS_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private InMemoryUcpConfigRepository $configRepository;

    protected function setUp(): void
    {
        $this->configRepository = new InMemoryUcpConfigRepository();
    }

    public function testAShopWithNothingExposedNeedsSetup(): void
    {
        $readiness = $this->provider()->readiness(Context::createDefaultContext());

        self::assertSame(ShopReadiness::STATUS_SETUP_NEEDED, $readiness->status);
        self::assertSame(0, $readiness->preparedCount);
        self::assertSame(1, $readiness->completedSteps());
        self::assertFalse($readiness->channels[0]->prepared);
        self::assertSame([], $readiness->channels[0]->findings);
    }

    public function testAPreparedShopWithoutAnAgenticChannelStillNeedsAnAction(): void
    {
        $this->expose(self::STOREFRONT_ID);

        $readiness = $this->provider(agenticSalesChannelCount: 0)->readiness(Context::createDefaultContext());

        self::assertSame(ShopReadiness::STATUS_ACTION_NEEDED, $readiness->status);
        self::assertSame(1, $readiness->preparedCount);
        self::assertSame(2, $readiness->completedSteps());
    }

    public function testEveryStepDoneAndNoErrorReportsReady(): void
    {
        $this->expose(self::STOREFRONT_ID);

        $readiness = $this->provider(agenticSalesChannelCount: 1, withSigningKey: true)->readiness(Context::createDefaultContext());

        self::assertSame(ShopReadiness::STATUS_READY, $readiness->status);
        self::assertSame(3, $readiness->completedSteps());
    }

    public function testAnExposedChannelThatNoAgentCanReachIsNotReportedReady(): void
    {
        // Every step is done, but the headless channel has no domain and no signing
        // key, so the validator raises errors and the step count alone would lie.
        $this->expose(self::HEADLESS_ID);

        $readiness = $this->provider(agenticSalesChannelCount: 1)->readiness(Context::createDefaultContext());

        self::assertSame(3, $readiness->completedSteps());
        self::assertSame(ShopReadiness::STATUS_ACTION_NEEDED, $readiness->status);

        $headless = $this->channel($readiness, self::HEADLESS_ID);
        self::assertNotSame([], $headless->findings);
        self::assertFalse($headless->hasDomain());
    }

    public function testItReportsTheActiveProductCountPerChannelAndZeroWhenUnknown(): void
    {
        $readiness = $this->provider(productCounts: [self::STOREFRONT_ID => 12480])->readiness(Context::createDefaultContext());

        self::assertSame(12480, $this->channel($readiness, self::STOREFRONT_ID)->activeProductCount);
        self::assertSame(0, $this->channel($readiness, self::HEADLESS_ID)->activeProductCount);
    }

    public function testTheSerialisedPayloadCarriesTheThreeSteps(): void
    {
        $this->expose(self::STOREFRONT_ID);

        $payload = $this->provider()->readiness(Context::createDefaultContext())->jsonSerialize();

        self::assertTrue($payload['steps']['installed']['done']);
        self::assertTrue($payload['steps']['prepared']['done']);
        self::assertSame(1, $payload['steps']['prepared']['count']);
        self::assertFalse($payload['steps']['connected']['done']);
        self::assertCount(2, $payload['channels']);
    }

    private function expose(string $salesChannelId): void
    {
        $this->configRepository->save($salesChannelId, UcpConfig::fromArray([
            'active' => true,
            'enabledCapabilities' => ['catalog', 'cart'],
            'enabledTransports' => ['rest'],
            'platformAllowlist' => ['agent.example.com'],
        ]));
    }

    private function channel(ShopReadiness $readiness, string $salesChannelId): ChannelReadiness
    {
        foreach ($readiness->channels as $channel) {
            if ($channel->id === $salesChannelId) {
                return $channel;
            }
        }

        self::fail(\sprintf('Expected sales channel %s in the readiness payload.', $salesChannelId));
    }

    /**
     * @param array<string, int> $productCounts
     */
    private function provider(
        array $productCounts = [],
        int $agenticSalesChannelCount = 0,
        bool $withSigningKey = false,
    ): ShopReadinessProvider {
        $typeResolver = new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Other, [
            self::STOREFRONT_ID => SalesChannelTypeClassification::Storefront,
            self::HEADLESS_ID => SalesChannelTypeClassification::Headless,
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

        return new ShopReadinessProvider(
            new SalesChannelViewProvider($this->salesChannelRepository(), $typeResolver),
            $configService,
            new ChannelFindingsResolver(new UcpConfigValidator(), $this->signingKeyService($withSigningKey)),
            $this->metrics($productCounts, $agenticSalesChannelCount),
        );
    }

    /**
     * @param array<string, int> $productCounts
     */
    private function metrics(array $productCounts, int $agenticSalesChannelCount): OnboardingMetricsInterface
    {
        return new StaticOnboardingMetrics($productCounts, $agenticSalesChannelCount);
    }

    private function signingKeyService(bool $withSigningKey): UcpSigningKeyService
    {
        $keys = $withSigningKey
            ? [new ManagedSigningKey('key-1', 'public-pem', 'private-pem')]
            : [];

        $repository = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $repository->method('allManaged')->willReturn($keys);

        $manager = $this->createMock(SigningKeyManagerInterface::class);
        $manager->method('toPublicKey')->willReturn(new PublicSigningKey('key-1'));

        return new UcpSigningKeyService($repository, $manager);
    }

    /**
     * @return EntityRepository<SalesChannelCollection>
     */
    private function salesChannelRepository(): EntityRepository
    {
        $channels = new SalesChannelCollection([
            $this->salesChannel(self::STOREFRONT_ID, 'Storefront', Defaults::SALES_CHANNEL_TYPE_STOREFRONT, 'https://shop.example.com'),
            $this->salesChannel(self::HEADLESS_ID, 'Headless', Defaults::SALES_CHANNEL_TYPE_API, null),
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
