<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\ShopwareRuntimeConfigurationResolver;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Model\Http\HttpRequest;

/** @internal */
#[CoversClass(ShopwareRuntimeConfigurationResolver::class)]
final class ShopwareRuntimeConfigurationResolverTest extends TestCase
{
    #[Test]
    public function testItResolvesThePathPrefixedChannelOfTheRequestCoreTransformed(): void
    {
        $cartRequestWithStrippedPrefix = Request::create('https://shop.example/ucp/v1/carts', Request::METHOD_POST);
        $cartRequestWithStrippedPrefix->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/ucp/v1/carts');

        $runtimeConfiguration = $this->resolver($cartRequestWithStrippedPrefix)
            ->resolve(new HttpRequest('POST', 'https://shop.example/ucp/v1/carts', [], [], ''));

        static::assertSame('sales-channel-de', $runtimeConfiguration->tenantIdentifier);
        static::assertSame('https://shop.example/de', $runtimeConfiguration->baseUri);
    }

    #[Test]
    public function testItResolvesThePathPrefixedChannelOfTheMainRequestWhileASubRequestIsCurrent(): void
    {
        $mcpRequestWithStrippedPrefix = Request::create('https://shop.example/ucp/mcp', Request::METHOD_POST);
        $mcpRequestWithStrippedPrefix->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/ucp/mcp');

        $mainRequestChannelConfiguration = $this->resolver($mcpRequestWithStrippedPrefix, Request::create('https://shop.example/store-api/_mcp', Request::METHOD_POST))
            ->resolve(new HttpRequest('POST', 'https://shop.example/ucp/mcp', [], [], ''));

        static::assertSame('sales-channel-de', $mainRequestChannelConfiguration->tenantIdentifier);
    }

    #[Test]
    public function testItIgnoresTheOriginalUriOfAnotherRequestOnTheStack(): void
    {
        $unrelatedRequest = Request::create('https://shop.example/checkout');
        $unrelatedRequest->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/checkout');

        $runtimeConfiguration = $this->resolver($unrelatedRequest)
            ->resolve(new HttpRequest('POST', 'https://shop.example/ucp/v1/carts', [], [], ''));

        static::assertSame('sales-channel-root', $runtimeConfiguration->tenantIdentifier);
        static::assertSame('https://shop.example', $runtimeConfiguration->baseUri);
    }

    private function resolver(Request $mainRequest, ?Request $subRequest = null): ShopwareRuntimeConfigurationResolver
    {
        $ucpConfigRepository = $this->createStub(UcpConfigRepositoryInterface::class);
        $ucpConfigRepository->method('find')->willReturn(new UcpConfig(active: true));

        /** @var StaticEntityRepository<SalesChannelDomainCollection> $domainRepository */
        $domainRepository = new StaticEntityRepository([new SalesChannelDomainCollection([
            $this->domain('domain-root', 'sales-channel-root', 'https://shop.example'),
            $this->domain('domain-de', 'sales-channel-de', 'https://shop.example/de'),
        ])]);

        $requestStack = new RequestStack();
        $requestStack->push($mainRequest);
        if (null !== $subRequest) {
            $requestStack->push($subRequest);
        }

        return new ShopwareRuntimeConfigurationResolver(
            new UcpConfigService($ucpConfigRepository, $this->createStub(LegacyConfigStoreInterface::class)),
            new SalesChannelDomainResolver($domainRepository),
            new ShopwareVersionDetector('6.7.0.0'),
            $requestStack,
        );
    }

    private function domain(string $id, string $salesChannelId, string $url): SalesChannelDomainEntity
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId($id);
        $domain->setSalesChannelId($salesChannelId);
        $domain->setLanguageId('language-id');
        $domain->setCurrencyId('currency-id');
        $domain->setUrl($url);

        return $domain;
    }
}
