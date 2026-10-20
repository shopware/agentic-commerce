<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileLocator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\Twig\UcpAgenticFilesExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** @internal */
#[CoversClass(UcpAgenticFilesExtension::class)]
final class UcpAgenticFilesExtensionTest extends TestCase
{
    /** @var array<string, UcpConfig> */
    private array $configs = [];

    private UcpAgenticFilesExtension $extension;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(fn (string $salesChannelId): ?UcpConfig => $this->configs[$salesChannelId] ?? null);
        $repository->method('findMany')->willReturnCallback(
            fn (array $salesChannelIds): array => array_filter(
                $this->configs,
                static fn (string $salesChannelId): bool => \in_array($salesChannelId, $salesChannelIds, true),
                \ARRAY_FILTER_USE_KEY,
            ),
        );

        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturn(null);

        /** @var StaticEntityRepository<SalesChannelDomainCollection> $domainRepository */
        $domainRepository = new StaticEntityRepository([new SalesChannelDomainCollection([
            $this->domain('domain-root', 'https://shop.example'),
            $this->domain('domain-de', 'https://shop.example/de'),
        ])]);
        $this->requestStack = new RequestStack();

        $this->extension = new UcpAgenticFilesExtension(
            new UcpConfigService($repository, $legacyStore),
            new UcpProfileLocator(),
            new SalesChannelDomainResolver($domainRepository),
            $this->requestStack,
        );
    }

    public function testItExposesConfiguredSalesChannelAsActive(): void
    {
        $this->configs = [
            'sales-channel-a' => UcpConfig::fromArray(['active' => true]),
        ];

        static::assertTrue($this->extension->isUcpActive('sales-channel-a'));
    }

    public function testItResolvesSalesChannelEntityId(): void
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-a');

        $this->configs = [
            'sales-channel-a' => UcpConfig::fromArray(['active' => true]),
        ];

        static::assertTrue($this->extension->isUcpActive($salesChannel));
    }

    public function testItDoesNotExposeInactiveOrMissingSalesChannels(): void
    {
        $this->configs = [
            'sales-channel-a' => UcpConfig::fromArray(['active' => false]),
        ];

        static::assertFalse($this->extension->isUcpActive('sales-channel-a'));
        static::assertFalse($this->extension->isUcpActive('sales-channel-b'));
        static::assertFalse($this->extension->isUcpActive(null));
    }

    public function testItRegistersTwigFunction(): void
    {
        static::assertSame('swag_agentic_commerce_ucp_active', $this->extension->getFunctions()[0]->getName());
        static::assertSame('swag_agentic_commerce_ucp_profile_url', $this->extension->getFunctions()[1]->getName());
    }

    public function testItGivesTheProfileUrlOnlyForADomainWithoutAPath(): void
    {
        static::assertSame('https://shop.example/.well-known/ucp', $this->extension->ucpProfileUrl('https://shop.example'));
        static::assertNull($this->extension->ucpProfileUrl('https://shop.example/de'));
    }

    public function testWithoutABaseUrlItGivesNoProfileUrlBelowThePathPrefixOfTheRequestedDomain(): void
    {
        $llmsTxtRequestWithStrippedPrefix = Request::create('https://shop.example/llms.txt');
        $llmsTxtRequestWithStrippedPrefix->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/llms.txt');
        $this->requestStack->push($llmsTxtRequestWithStrippedPrefix);

        static::assertNull($this->extension->ucpProfileUrl());
    }

    public function testWithoutABaseUrlOrARequestItGivesTheRootRelativeProfilePath(): void
    {
        static::assertSame('/.well-known/ucp', $this->extension->ucpProfileUrl());
    }

    private function domain(string $id, string $url): SalesChannelDomainEntity
    {
        $registeredDomain = new SalesChannelDomainEntity();
        $registeredDomain->setId($id);
        $registeredDomain->setSalesChannelId('sales-channel-'.$id);
        $registeredDomain->setLanguageId('language-id');
        $registeredDomain->setCurrencyId('currency-id');
        $registeredDomain->setUrl($url);

        return $registeredDomain;
    }
}
