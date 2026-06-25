<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\ProductListResponse;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\StaticSalesChannelContextService;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCatalogGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Catalog\Product as UcpProduct;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareCatalogGatewayTest extends TestCase
{
    #[Test]
    public function testSearchClampsRequestedLimitToConfiguredCatalogLimit(): void
    {
        $criteriaLimits = [];
        $requestLimits = [];
        $products = [
            $this->product('product-a', 'A', 10.0),
            $this->product('product-b', 'B', 20.0),
            $this->product('product-c', 'C', 30.0),
        ];
        $searchRoute = $this->createMock(AbstractProductSearchRoute::class);
        $searchRoute->method('load')->willReturnCallback(
            function (Request $request, SalesChannelContext $context, Criteria $criteria) use (&$criteriaLimits, &$requestLimits, $products): ProductSearchRouteResponse {
                $criteriaLimits[] = $criteria->getLimit();
                $requestLimits[] = $request->query->getInt('limit');

                return $this->searchResponse($products, $criteria);
            },
        );
        $gateway = $this->gateway(2, searchRoute: $searchRoute);

        $products = $gateway->search('speaker', 1000, new RequestContext('shop.test'));

        self::assertSame([2], $criteriaLimits);
        self::assertSame([2], $requestLimits);
        self::assertSame(['product-a', 'product-b'], array_map(static fn (UcpProduct $product): string => $product->id, $products));
    }

    #[Test]
    public function testLookupClampsIdsAndLoadsProductsInOneBatch(): void
    {
        $criteriaIds = [];
        $products = [
            $this->product('product-b', 'B', 20.0),
            $this->product('product-a', 'A', 10.0),
            $this->product('product-c', 'C', 30.0),
        ];
        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            function (Criteria $criteria, SalesChannelContext $context) use (&$criteriaIds, $products): ProductListResponse {
                $ids = [];
                foreach ($criteria->getIds() as $id) {
                    if (\is_string($id)) {
                        $ids[] = $id;
                    }
                }

                $criteriaIds[] = $ids;

                return $this->listResponse(array_values(array_filter(
                    $products,
                    static fn (SalesChannelProductEntity $product): bool => \in_array($product->getId(), $ids, true),
                )), $criteria);
            },
        );
        $gateway = $this->gateway(2, listRoute: $listRoute);

        $products = $gateway->lookup(['product-a', 'product-b', 'product-c'], new RequestContext('shop.test'));

        self::assertSame([['product-a', 'product-b']], $criteriaIds);
        self::assertSame(['product-a', 'product-b'], array_map(static fn (UcpProduct $product): string => $product->id, $products));
        self::assertSame(
            [['id' => 'product-a', 'match' => 'exact']],
            $products[0]->extra['variants'][0]['inputs'],
        );
    }

    private function gateway(
        int $catalogResultLimit,
        ?AbstractProductSearchRoute $searchRoute = null,
        ?AbstractProductListRoute $listRoute = null,
    ): ShopwareCatalogGateway {
        $salesChannelContext = $this->createSalesChannelContext();
        $config = UcpConfig::fromArray(['catalogResultLimit' => $catalogResultLimit]);

        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn($config);

        $legacyConfigStore = $this->createMock(LegacyConfigStoreInterface::class);

        return new ShopwareCatalogGateway(
            $this->contextResolver($salesChannelContext),
            new ContextTokenGenerator(),
            new UcpConfigService($configRepository, $legacyConfigStore),
            $searchRoute ?? $this->createMock(AbstractProductSearchRoute::class),
            $listRoute ?? $this->createMock(AbstractProductListRoute::class),
            $this->createMock(AbstractProductDetailRoute::class),
            new ShopwareDataMapper(),
        );
    }

    private function createSalesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        return $context;
    }

    private function contextResolver(SalesChannelContext $salesChannelContext): SalesChannelContextResolver
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('domain-id');
        $domain->setUrl('https://shop.test');
        $domain->setSalesChannelId('sales-channel-id');
        $domain->setLanguageId('language-id');
        $domain->setCurrencyId('currency-id');

        /** @var EntityRepository<SalesChannelDomainCollection>&MockObject $domainRepository */
        $domainRepository = $this->createMock(EntityRepository::class);
        $domainRepository->method('search')->willReturn(new EntitySearchResult(
            'sales_channel_domain',
            1,
            new SalesChannelDomainCollection([$domain]),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        ));

        $contextPersister = $this->createMock(SalesChannelContextPersister::class);
        $contextPersister->method('load')->willReturn([]);

        return new SalesChannelContextResolver(
            new SalesChannelDomainResolver($domainRepository),
            new StaticSalesChannelContextService($salesChannelContext),
            $contextPersister,
        );
    }

    private function product(string $id, string $name, float $price): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId($id);
        $product->setName($name);
        $product->setProductNumber($id);
        $product->setCalculatedPrice(new CalculatedPrice($price, $price, new CalculatedTaxCollection(), new TaxRuleCollection()));

        return $product;
    }

    /**
     * @param list<SalesChannelProductEntity> $products
     */
    private function searchResponse(array $products, Criteria $criteria): ProductSearchRouteResponse
    {
        return new ProductSearchRouteResponse(new ProductListingResult(
            'product',
            \count($products),
            new ProductCollection($products),
            null,
            $criteria,
            Context::createDefaultContext(),
        ));
    }

    /**
     * @param list<SalesChannelProductEntity> $products
     */
    private function listResponse(array $products, Criteria $criteria): ProductListResponse
    {
        return new ProductListResponse(new EntitySearchResult(
            'product',
            \count($products),
            new ProductCollection($products),
            null,
            $criteria,
            Context::createDefaultContext(),
        ));
    }
}
